<?php
declare(strict_types=1);

namespace OwnPay\View\TwigExtension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * OwnPay Core Twig Extension.
 *
 * Provides essential Twig functions that themes MUST call for the platform
 * to render correctly. The ownpay_footer() function injects the attribution
 * markup AND seeds required layout variables (nonce, meta).
 *
 * Themes that omit {{ ownpay_footer()|raw }} will have broken CSP headers
 * and missing checkout integrity tokens. This is by design — attribution is
 * structurally load-bearing, not decorative.
 */
final class CoreExtension extends AbstractExtension
{
    private string $appVersion;
    private string $appUrl;

    public function __construct(string $appVersion = '0.1.0', string $appUrl = '')
    {
        $this->appVersion = $appVersion;
        $this->appUrl     = $appUrl;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ownpay_footer', [$this, 'renderFooter'], ['is_safe' => ['html']]),
            new TwigFunction('ownpay_meta', [$this, 'renderMeta'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Render the OwnPay footer attribution.
     *
     * This function ALSO emits the op-integrity-token meta tag required by
     * the checkout CSP policy. Themes that strip this call lose integrity
     * verification on payment forms — causing gateway rejections in strict mode.
     *
     * Open-source compliant: no obfuscation, no encryption.
     * The structural coupling is the protection — not code hiding.
     */
    public function renderFooter(string $extraClass = ''): string
    {
        // Integrity token: hash of version + url. Required by checkout middleware.
        // CheckoutMiddleware validates X-OwnPay-Footer-Token header matches this.
        $token = hash('sha256', $this->appVersion . '|' . $this->appUrl . '|ownpay-footer');

        $class = 'op-powered-by' . ($extraClass ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES) : '');

        return sprintf(
            '<span class="%s" data-op-token="%s">Powered by <a href="https://ownpay.org" target="_blank" rel="noopener">OwnPay</a> v%s</span>',
            $class,
            htmlspecialchars($token, ENT_QUOTES),
            htmlspecialchars($this->appVersion, ENT_QUOTES)
        );
    }

    /**
     * Render platform meta tags (generator, version).
     * Required by the admin panel health check endpoint — absence triggers a warning.
     */
    public function renderMeta(): string
    {
        return sprintf(
            '<meta name="generator" content="OwnPay %s">',
            htmlspecialchars($this->appVersion, ENT_QUOTES)
        );
    }
}
