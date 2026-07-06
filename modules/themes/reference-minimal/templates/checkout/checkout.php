<?php
/**
 * @var callable $esc
 * @var mixed $txn
 * @var mixed $gateways
 * @var mixed $brand
 * @var mixed $checkout_hash
 */
require_once __DIR__ . '/layout.php';

use function OwnPay\Modules\Themes\ReferenceMinimal\render_layout;

$amountVal = is_array($txn ?? null) ? ($txn['amount'] ?? '0.00') : '0.00';
$amount = is_scalar($amountVal) ? (string) $amountVal : '0.00';
$currencyVal = is_array($txn ?? null) ? ($txn['currency'] ?? '') : '';
$currency = is_scalar($currencyVal) ? (string) $currencyVal : '';
$trxIdVal = is_array($txn ?? null) ? ($txn['trx_id'] ?? '') : '';
$trxId = is_scalar($trxIdVal) ? (string) $trxIdVal : '';
/** @var array<string, mixed> $brandArr */
$brandArr = is_array($brand ?? null) ? $brand : [];
$gatewaysArr = is_array($gateways ?? null) ? $gateways : [];
$checkoutHashVal = is_string($checkout_hash ?? null) ? $checkout_hash : '';

$groupLabels = ['mfs' => 'Mobile Banking', 'bank' => 'Net Banking', 'global' => 'Cards', 'express' => 'Express Checkout'];
$groupsHtml = '';
foreach ($groupLabels as $groupKey => $groupLabel) {
    $groupGateways = is_array($gatewaysArr[$groupKey] ?? null) ? $gatewaysArr[$groupKey] : [];
    if (empty($groupGateways)) {
        continue;
    }
    $itemsHtml = '';
    foreach ($groupGateways as $gw) {
        if (!is_array($gw)) {
            continue;
        }
        $gwSlugVal = $gw['slug'] ?? '';
        $gwSlug = is_scalar($gwSlugVal) ? (string) $gwSlugVal : '';
        $gwNameVal = $gw['name'] ?? $gwSlug;
        $gwName = is_scalar($gwNameVal) ? (string) $gwNameVal : $gwSlug;
        $itemsHtml .= '<button type="submit" name="gateway" value="' . $esc($gwSlug) . '" class="op-ref-gateway-item">' . $esc($gwName) . '</button>';
    }
    if ($itemsHtml === '') {
        continue;
    }
    $groupsHtml .= '<div class="op-ref-gateway-group"><h3>' . $esc($groupLabel) . '</h3><div class="op-ref-gateway-list">' . $itemsHtml . '</div></div>';
}

$body = '<div class="op-ref-amount">' . $esc($amount) . ' ' . $esc($currency) . '</div>'
    . '<form method="POST" action="/checkout/' . $esc($trxId) . '/pay">'
    . '<input type="hidden" name="checkout_hash" value="' . $esc($checkoutHashVal) . '">'
    . $groupsHtml
    . '</form>';

$brandName = (is_string($brandArr['name'] ?? null) && $brandArr['name'] !== '') ? $brandArr['name'] : 'OwnPay';

echo render_layout($brandName . ' - Checkout', $body, $brandArr, $esc);
