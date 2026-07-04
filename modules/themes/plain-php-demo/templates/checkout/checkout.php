<?php
/**
 * Plain-PHP proof-of-concept checkout template.
 * @var callable $esc
 * @var array $txn
 * @var array $brand
 * @var array $gateways
 */
$brandName = is_array($brand ?? null) ? (string) ($brand['name'] ?? 'Checkout') : 'Checkout';
$amount = is_array($txn ?? null) ? (string) ($txn['amount'] ?? '') : '';
$currency = is_array($txn ?? null) ? (string) ($txn['currency'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $esc($brandName) ?> - Checkout (Plain PHP Demo)</title>
</head>
<body>
    <main style="max-width:480px;margin:40px auto;font-family:system-ui,sans-serif;">
        <h1><?= $esc($brandName) ?></h1>
        <p style="color:#666;">Rendered by the php engine.</p>
        <p><strong>Amount:</strong> <?= $esc($amount) ?> <?= $esc($currency) ?></p>
        <h2>Payment methods</h2>
        <ul>
            <?php foreach ((is_array($gateways ?? null) ? $gateways : []) as $gw): ?>
                <li><?= $esc(is_array($gw) ? (string) ($gw['name'] ?? '') : (string) $gw) ?></li>
            <?php endforeach; ?>
        </ul>
    </main>
</body>
</html>
