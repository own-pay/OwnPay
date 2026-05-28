<?php
declare(strict_types=1);

$res = json_decode(shell_exec('php scratch/audit_live_bypass.php'), true);
echo 'Count: ' . count($res) . PHP_EOL;
foreach ($res as $slug => $info) {
    echo '- ' . $slug . PHP_EOL;
}
