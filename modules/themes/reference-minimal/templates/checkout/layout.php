<?php

declare(strict_types=1);

namespace OwnPay\Modules\Themes\ReferenceMinimal;

/**
 * Shared chrome wrapper for the 3 reference-minimal page templates. Plain
 * PHP has no native template-inheritance mechanism, so this is the explicit
 * function-call equivalent of Twig's {% block %} layout inheritance.
 *
 * Reads customization directly from $brand (BrandThemeService's resolved
 * shape: name, logo, accent_color, show_powered_by, footer_text,
 * support_email, custom_css) - all keys optional, since payment-link-amount.php
 * has no real $brand and passes [].
 *
 * @param array<string,mixed> $brand
 */
function render_layout(string $title, string $bodyHtml, array $brand, callable $esc): string
{
    $brandName = is_string($brand['name'] ?? null) && $brand['name'] !== '' ? $brand['name'] : 'OwnPay';
    $logo = is_string($brand['logo'] ?? null) ? $brand['logo'] : '';
    $headerHtml = $logo !== ''
        ? '<img src="' . $esc($logo) . '" alt="' . $esc($brandName) . '" class="op-ref-logo">'
        : '<span class="op-ref-brand-name">' . $esc($brandName) . '</span>';

    $showPoweredBy = array_key_exists('show_powered_by', $brand) ? (bool) $brand['show_powered_by'] : true;
    $footerText = is_string($brand['footer_text'] ?? null) ? $brand['footer_text'] : '';
    $footerParts = [];
    if ($footerText !== '') {
        $footerParts[] = '<span class="op-ref-footer-text">' . $esc($footerText) . '</span>';
    }
    if ($showPoweredBy) {
        $footerParts[] = '<span>Powered by OwnPay</span>';
    }
    $footerHtml = implode(' &middot; ', $footerParts);

    // Security badges always render for this theme (fixed design choice, not
    // a setting - see plan Global Constraints on why this isn't configurable).
    $badgesHtml = '<div class="op-ref-badges"><span>&#128274; 256-bit encryption</span></div>';

    // Dark mode always defaults to 'light' for this theme (fixed - see Global
    // Constraints); the toggle button still fully switches/persists client-side.
    $defaultMode = 'light';

    $customCss = is_string($brand['custom_css'] ?? null) ? $brand['custom_css'] : '';
    // Strip </style> occurrences so custom_css cannot break out of the style
    // context and inject arbitrary markup (custom_css is admin-authored but
    // still untrusted at render time - see final-review finding #2).
    $customCssSafe = preg_replace('/<\/style\s*>/i', '', $customCss) ?? '';
    $customCssBlock = $customCssSafe !== '' ? '<style id="op-custom-css">' . $customCssSafe . '</style>' : '';

    $escTitle = $esc($title);

    return <<<HTML
<!doctype html>
<html lang="en" data-op-theme-default="{$defaultMode}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$escTitle}</title>
    <link rel="stylesheet" href="/assets/css/reference-minimal.css">
    {$customCssBlock}
</head>
<body>
    <div class="op-ref-shell">
        <header class="op-ref-header">
            {$headerHtml}
            <button type="button" class="op-ref-dark-toggle" id="op-ref-dark-toggle" aria-label="Toggle dark mode">&#9789;</button>
        </header>
        <main class="op-ref-main">
            {$bodyHtml}
        </main>
        {$badgesHtml}
        <footer class="op-ref-footer">
            {$footerHtml}
        </footer>
    </div>
    <script src="/assets/js/reference-minimal.js"></script>
</body>
</html>
HTML;
}
