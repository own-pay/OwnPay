<?php
$files = [
    'src/Repository/BaseRepository.php',
    'src/Service/Domain/DomainService.php',
    'src/Service/Sms/SmsParserService.php',
    'src/Repository/TransactionRepository.php',
    'src/Repository/GatewayConfigRepository.php',
    'src/Repository/SmsParsedRepository.php',
    'src/Repository/SmsTemplateRepository.php',
    'src/Event/EventManager.php',
    'src/Plugin/PluginLoader.php',
    'src/Security/FieldEncryptor.php',
    'src/Security/PiiMasker.php',
    'src/Http/Request.php',
    'src/Service/System/EnvironmentService.php',
];

$allOk = true;
foreach ($files as $f) {
    exec("php -l {$f} 2>&1", $output, $code);
    $status = $code === 0 ? 'OK' : 'FAIL';
    echo "{$status}: {$f}\n";
    if ($code !== 0) {
        echo "  " . implode("\n  ", $output) . "\n";
        $allOk = false;
    }
    $output = [];
}
echo $allOk ? "\nAll files OK\n" : "\nSome files have errors!\n";
