<?php
/**
 * @var callable $esc
 * @var mixed $link
 * @var mixed $csrf_token
 * @var mixed $error
 */
require_once __DIR__ . '/layout.php';

use function OwnPay\Modules\Themes\ReferenceMinimal\render_layout;

$linkArr = is_array($link ?? null) ? $link : [];
$slugVal = $linkArr['slug'] ?? '';
$slug = is_scalar($slugVal) ? (string) $slugVal : '';
$currencyVal = $linkArr['currency'] ?? '';
$currency = is_scalar($currencyVal) ? (string) $currencyVal : '';
$minAmountVal = $linkArr['min_amount'] ?? '0';
$minAmount = is_scalar($minAmountVal) ? (string) $minAmountVal : '0';
$maxAmountVal = $linkArr['max_amount'] ?? '';
$maxAmount = is_scalar($maxAmountVal) ? (string) $maxAmountVal : '';
$errorStr = is_string($error ?? null) ? $error : '';
$csrfVal = $csrf_token ?? '';
$csrfStr = is_scalar($csrfVal) ? (string) $csrfVal : '';

$errorHtml = $errorStr !== '' ? '<p style="color:#dc2626;font-size:13px;">' . $esc($errorStr) . '</p>' : '';
$hintParts = [];
if ($minAmount !== '' && is_numeric($minAmount) && bccomp($minAmount, '0', 2) > 0) {
    $hintParts[] = 'Min ' . $esc($minAmount);
}
if ($maxAmount !== '') {
    $hintParts[] = 'Max ' . $esc($maxAmount);
}
$hintHtml = !empty($hintParts) ? '<p style="font-size:12px;color:var(--op-ref-muted);">' . implode(' &middot; ', $hintParts) . '</p>' : '';

$body = '<form method="POST" action="/pay/' . $esc($slug) . '/submit">'
    . '<input type="hidden" name="_csrf_token" value="' . $esc($csrfStr) . '">'
    . $errorHtml
    . '<label for="op-ref-amount" style="display:block;font-size:13px;margin-bottom:6px;">Enter amount (' . $esc($currency) . ')</label>'
    . '<input type="number" step="0.01" name="amount" id="op-ref-amount" class="op-ref-input" style="margin-bottom:8px;" required>'
    . $hintHtml
    . '<button type="submit" class="op-ref-btn-primary" style="margin-top:12px;">Continue</button>'
    . '</form>';

echo render_layout('Enter Payment Amount', $body, [], $esc);
