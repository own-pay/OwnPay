<?php

declare(strict_types=1);

namespace OwnPay\Service\Sms;

use OwnPay\Core\Database;
use OwnPay\Repository\SettingsRepository;

/**
 * Resumable backfill: encrypts pre-migration plaintext op_sms_parsed.body values.
 *
 * Text written before the encryption migration are raw plaintext. This job encrypts
 * them in id-ordered batches. Progress is kept in op_migrations (`sms_body_encryption_backfill_v1`
 * absent = not started; a settings marker records the resume offset), and completion is
 * recorded with counts + a verification pass that proves no plaintext body remains.
 *
 * Safety properties:
 * - Idempotent: rows already carrying the `v1:` prefix are skipped.
 * - Resumable: the last processed id is persisted after every batch; a crashed run resumes there.
 * - Verifiable: a final `SELECT COUNT(*) WHERE body NOT LIKE 'v1:%'` proves full conversion.
 * - Non-destructive: only `body` is rewritten; every other column is untouched (no schema change).
 * - Never logs message content, only counts.
 *
 * Existing database backups are NOT retroactively encrypted; they may hold plaintext
 * copies and must be protected accordingly (see docs/DATA_RETENTION.md).
 */
final class SmsBodyBackfillService
{
    private const MIGRATION_KEY = 'sms_body_encryption_backfill_v1';
    private const BATCH_SIZE = 250;

    public function __construct(
        private readonly Database $db,
        private readonly \OwnPay\Security\SmsBodyCipher $cipher,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * @return array{status: string, processed: int, converted: int, remaining: int, verified: bool}
     */
    public function run(): array
    {
        $this->ensureMarkerTable();
        if ($this->isComplete()) {
            return ['status' => 'already_complete', 'processed' => 0, 'converted' => 0, 'remaining' => 0, 'verified' => true];
        }

        $offset = $this->loadOffset();
        $processed = 0;
        $converted = 0;

        while (true) {
            $rows = $this->db->fetchAll(
                "SELECT id, body FROM op_sms_parsed
                 WHERE body IS NOT NULL AND body NOT LIKE 'v1:%' AND id > :offset
                 ORDER BY id ASC LIMIT " . self::BATCH_SIZE,
                ['offset' => $offset]
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!isset($row['id'], $row['body']) || !is_scalar($row['id']) || !is_scalar($row['body'])) {
                    continue;
                }
                $id = (int) $row['id'];
                $plain = (string) $row['body'];
                if ($plain === '' || $this->cipher->isEncrypted($plain)) {
                    $offset = $id;
                    $processed++;
                    continue;
                }
                $this->db->execute(
                    'UPDATE op_sms_parsed SET body = :enc WHERE id = :id AND body NOT LIKE :prefix',
                    ['enc' => $this->cipher->encrypt($plain), 'id' => $id, 'prefix' => 'v1:%']
                );
                $offset = $id;
                $processed++;
                $converted++;
            }

            $this->saveOffset($offset);
        }

        $remaining = $this->countPlaintext();
        $verified = $remaining === 0;
        if ($verified) {
            // Record completion so future update cycles never redo the pass.
            $this->db->execute(
                'INSERT INTO op_migrations (migration) VALUES (:m) ON DUPLICATE KEY UPDATE executed_at = CURRENT_TIMESTAMP(6)',
                ['m' => self::MIGRATION_KEY]
            );
            $this->saveOffset(0);
        }

        return ['status' => $verified ? 'complete' : 'incomplete', 'processed' => $processed, 'converted' => $converted, 'remaining' => $remaining, 'verified' => $verified];
    }

    /**
     * Number of rows whose body is not yet a versioned ciphertext.
     */
    public function countPlaintext(): int
    {
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_sms_parsed WHERE body IS NOT NULL AND body NOT LIKE 'v1:%'"
        );
        return is_scalar($count) ? (int) $count : 0;
    }

    private function isComplete(): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM op_migrations WHERE migration = :m LIMIT 1',
            ['m' => self::MIGRATION_KEY]
        );
        return $row !== null;
    }

    private function ensureMarkerTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS `op_migrations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function loadOffset(): int
    {
        $val = $this->settings->get('runtime', 'migration.sms_body_encryption.offset');
        return is_string($val) && ctype_digit($val) ? (int) $val : 0;
    }

    private function saveOffset(int $offset): void
    {
        $this->settings->set('runtime', 'migration.sms_body_encryption.offset', (string) $offset);
    }
}
