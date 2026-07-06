<?php
/**
 * Plain-PHP proof-of-concept checkout template.
 * @var callable $esc
 * @var mixed $txn
 * @var mixed $brand
 * @var mixed $gateways
 */
$brandNameVal = is_array($brand ?? null) ? ($brand['name'] ?? 'Checkout') : 'Checkout';
$brandName = is_scalar($brandNameVal) ? (string) $brandNameVal : 'Checkout';
$amountVal = is_array($txn ?? null) ? ($txn['amount'] ?? '') : '';
$amount = is_scalar($amountVal) ? (string) $amountVal : '';
$currencyVal = is_array($txn ?? null) ? ($txn['currency'] ?? '') : '';
$currency = is_scalar($currencyVal) ? (string) $currencyVal : '';
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
                <?php
                $gwNameVal = is_array($gw) ? ($gw['name'] ?? '') : $gw;
                $gwName = is_scalar($gwNameVal) ? (string) $gwNameVal : '';
                ?>
                <li><?= $esc($gwName) ?></li>
            <?php endforeach; ?>
        </ul>
    </main>
</body>
</html>
