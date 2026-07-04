<?php
/**
 * @var callable $esc
 * @var string $status
 * @var string $status_label
 * @var array $brand
 */
require_once __DIR__ . '/layout.php';

use function OwnPay\Modules\Themes\ReferenceMinimal\render_layout;

$brandArr = is_array($brand ?? null) ? $brand : [];
$statusStr = (string) ($status ?? '');
$statusLabelStr = (string) ($status_label ?? 'Status');

$icons = ['success' => '&#9989;', 'pending' => '&#9203;', 'awaiting_verification' => '&#9203;', 'processing' => '&#9203;', 'failed' => '&#10060;', 'cancelled' => '&#10060;', 'expired' => '&#8987;'];
$icon = $icons[$statusStr] ?? '&#8505;';

$body = '<div class="op-ref-status-icon">' . $icon . '</div>'
    . '<div class="op-ref-status-label">' . $esc($statusLabelStr) . '</div>';

echo render_layout($statusLabelStr, $body, $brandArr, $esc);
