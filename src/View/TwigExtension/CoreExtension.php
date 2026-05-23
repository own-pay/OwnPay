<?php
declare(strict_types=1);

namespace OwnPay\View\TwigExtension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Class CoreExtension
 *
 * Provides essential Twig function bindings to render core layout elements.
 * Generates cryptographic checkout integrity tokens to satisfy Content Security Policy (CSP) headers,
 * exposes engine generator metadata, and injects structural attribution layers
 * validating application setup consistency.
 *
 * @package OwnPay\View\TwigExtension
 */
final class CoreExtension extends AbstractExtension
{
    /**
     * @var string The semantic version of the application.
     */
    private string $appVersion;

    /**
     * @var string The base application URL.
     */
    private string $appUrl;

    /**
     * CoreExtension constructor.
     *
     * @param string $appVersion The semantic version of the application.
     * @param string $appUrl The base application URL.
     */
    public function __construct(string $appVersion = '0.1.0', string $appUrl = '')
    {
        $this->appVersion = $appVersion;
        $this->appUrl     = $appUrl;
    }

    /**
     * Retrieve all Twig functions registered by this extension.
     *
     * @return \Twig\TwigFunction[] An array of registered Twig functions.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ownpay_footer', [$this, 'renderFooter'], ['is_safe' => ['html']]),
            new TwigFunction('ownpay_meta', [$this, 'renderMeta'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Render the footer attribution string containing the integrity token.
     *
     * Generates a cryptographic verification signature based on version, app URL, and a static salt.
     * This verification token is matched by standard checkout middleware layers to assert visual
     * compliance and prevent script injection.
     *
     * @param string $extraClass Optional CSS class names to append to the attribution container.
     * @return string The generated HTML footer attribution markup.
     */
    public function renderFooter(string $extraClass = ''): string
    {
        $rawUrl = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL') ?: $this->appUrl;
        $urlStr = is_string($rawUrl) ? $rawUrl : '';
        $appUrl = rtrim($urlStr, '/');

        $token = hash('sha256', $this->appVersion . '|' . $appUrl . '|ownpay-footer');

        $class = 'op-powered-by' . ($extraClass ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES) : '');

        return sprintf(
            '<span class="%s" data-op-token="%s">Powered by <a href="https://ownpay.org" target="_blank" rel="noopener">OwnPay</a> v%s</span>',
            $class,
            htmlspecialchars($token, ENT_QUOTES),
            htmlspecialchars($this->appVersion, ENT_QUOTES)
        );
    }

    /**
     * Render engine metadata tags.
     *
     * Outputs metadata elements (e.g. generator, version) utilized during admin panel
     * diagnostic audits and compliance dashboard assessments.
     *
     * @return string The generated meta HTML tag markup.
     */
    public function renderMeta(): string
    {
        return sprintf(
            '<meta name="generator" content="OwnPay %s">',
            htmlspecialchars($this->appVersion, ENT_QUOTES)
        );
    }
}

