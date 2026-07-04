<?php
/**
 * @var callable $esc
 * @var array $link
 * @var string $csrf_token
 * @var string|null $error
 */
require_once __DIR__ . '/layout.php';

use function OwnPay\Modules\Themes\ReferenceMinimal\render_layout;

$linkArr = is_array($link ?? null) ? $link : [];
$slug = (string) ($linkArr['slug'] ?? '');
$currency = (string) ($linkArr['currency'] ?? '');
$minAmount = (string) ($linkArr['min_amount'] ?? '0');
$maxAmount = (string) ($linkArr['max_amount'] ?? '');
$errorStr = is_string($error ?? null) ? $error : '';
$csrfStr = (string) ($csrf_token ?? '');

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
