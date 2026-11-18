<?php

declare(strict_types=1);

/**
 * CLI tool: encrypt pre-migration plaintext op_sms_parsed.body rows in-place.
 *
 * AGENT RUNBOOK (see docs/DATA_RETENTION.md):
 *   php tools/reencrypt-sms-bodies.php --dry-run   # report what would be converted
 *   php tools/reencrypt-sms-bodies.php             # run the backfill (idempotent, resumable)
 *
 * This is the same SmsBodyBackfillService that UpdateService runs during self-update;
 * this entry point exists so operators can run it standalone (e.g. as the first
 * verification step after deploying for the first time).
 *
 * The tool boots the same .env + DI configuration the web app uses, so it reads
 * exactly the same database and ENCRYPTION_KEY. Nothing here sends data anywhere.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs on the CLI only.\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
$autoload = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run: composer install\n");
    exit(1);
}
require_once $autoload;

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createMutable($rootDir)->safeLoad();
}

$container = new \OwnPay\Container();
$container->instance(\OwnPay\Container::class, $container);
$registerServices = require $rootDir . '/config/services.php';
$registerServices($container);

$dryRun = in_array('--dry-run', $argv, true);

try {
    $db = $container->get(\OwnPay\Core\Database::class);
    if (!$db instanceof \OwnPay\Core\Database) {
        throw new \RuntimeException('Database service unavailable.');
    }
    $cipher = new \OwnPay\Security\SmsBodyCipher(
        $container->get(\OwnPay\Security\FieldEncryptor::class) instanceof \OwnPay\Security\FieldEncryptor
            ? $container->get(\OwnPay\Security\FieldEncryptor::class)
            : new \OwnPay\Security\FieldEncryptor()
    );
    $backfill = new \OwnPay\Service\Sms\SmsBodyBackfillService(
        $db,
        $cipher,
        new \OwnPay\Repository\SettingsRepository($db)
    );

    if ($dryRun) {
        $remaining = $backfill->countPlaintext();
        fwrite(STDOUT, "Plaintext body rows pending encryption: {$remaining}\n");
        exit($remaining === 0 ? 0 : 2);
    }

    $result = $backfill->run();
    fwrite(STDOUT, "SMS body backfill finished:\n");
    foreach ($result as $key => $value) {
        fwrite(STDOUT, "  {$key}: " . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . "\n");
    }
    exit($result['verified'] ? 0 : 3);
} catch (\Throwable $e) {
    fwrite(STDERR, "SMS body backfill failed: {$e->getMessage()}\n");
    exit(1);
}
