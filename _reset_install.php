<?php
require __DIR__ . '/vendor/autoload.php';
$db = \OwnPay\Core\Database::init('localhost', 'ownpay', 'root', 'root');
$db->execute('SET FOREIGN_KEY_CHECKS = 0');
$tables = $db->fetchAll('SHOW TABLES');
foreach ($tables as $t) {
    $name = array_values($t)[0];
    $db->execute("DROP TABLE IF EXISTS `{$name}`");
    echo "Dropped: {$name}\n";
}
$db->execute('SET FOREIGN_KEY_CHECKS = 1');
echo "All tables dropped. DB is clean.\n";

// Also remove temp config and installed marker
foreach (['op-config.php', 'op-temp-config.php'] as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        unlink($path);
        echo "Deleted: {$f}\n";
    }
}
$marker = __DIR__ . '/app/install/.installed';
if (file_exists($marker)) {
    unlink($marker);
    echo "Deleted: .installed\n";
}
echo "Ready for fresh install.\n";
