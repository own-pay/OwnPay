<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Class TwigExtensions
 *
 * Core template extensions for the OwnPay gateway, exposing essential functions and filters to Twig.
 * Integrates global security measures (CSRF, XSS filter sanitization on hook contexts), custom domain routing,
 * brand context indicators, and multi-currency formatting utilities.
 *
 * @package OwnPay\View
 */
final class TwigExtensions extends AbstractExtension
{
    /**
     * @var \OwnPay\Container The PSR-11 dependency injection container wrapper.
     */
    private Container $container;

    /**
     * TwigExtensions constructor.
     *
     * @param \OwnPay\Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Returns the name of the extension.
     *
     * @return string The extension name identifier.
     */
    public function getName(): string
    {
        return 'ownpay';
    }

    /**
     * Resolve and return all functions exposed to the Twig templates.
     *
     * @return \Twig\TwigFunction[] An array of registered Twig functions.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', [$this, 'csrfToken'], ['is_safe' => ['html']]),
            new TwigFunction('csrf_field', [$this, 'csrfField'], ['is_safe' => ['html']]),
            new TwigFunction('asset', [$this, 'asset']),
            new TwigFunction('env', [$this, 'env']),
            new TwigFunction('hook', [$this, 'hook'], ['is_safe' => ['html']]),
            new TwigFunction('hook_filter', [$this, 'hookFilter']),
            new TwigFunction('app_name', [$this, 'appName']),
            new TwigFunction('app_version', [$this, 'appVersion']),
            new TwigFunction('setting', [$this, 'setting']),
            new TwigFunction('flash_messages', [$this, 'flashMessages']),
        ];
    }

    /**
     * Resolve and return all filters exposed to the Twig templates.
     *
     * @return \Twig\TwigFilter[] An array of registered Twig filters.
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', [$this, 'formatMoney']),
            new TwigFilter('datetime', [$this, 'formatDatetime']),
            new TwigFilter('truncate', [$this, 'truncate']),
            new TwigFilter('slug', [$this, 'slugify']),
            new TwigFilter('time_ago', [$this, 'timeAgo']),
            new TwigFilter('format_bytes', [$this, 'formatBytes']),
        ];
    }

    /**
     * Retrieve the active CSRF token value from session storage.
     *
     * Ensures fallback token initialization if not previously generated.
     *
     * @return string The active CSRF token value, or empty if session is inactive.
     * @throws \Exception If cryptographic random bytes cannot be generated.
     */
    public function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['_csrf_token'];
        return is_string($token) ? $token : '';
    }

    /**
     * Output a hidden HTML input field containing the active CSRF token.
     *
     * @return string The escaped HTML markup for the CSRF hidden input.
     */
    public function csrfField(): string
    {
        $token = $this->csrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate a static asset URL appended with a cache-busting version parameter.
     *
     * @param string $path The relative path to the asset file.
     * @return string The resolved public asset URL.
     */
    public function asset(string $path): string
    {
        $configApp = $this->container->get('config.app');
        $version = '0.1.0';
        if (is_array($configApp) && isset($configApp['version']) && is_string($configApp['version'])) {
            $version = $configApp['version'];
        }
        $cleanPath = ltrim($path, '/');
        return '/assets/' . $cleanPath . '?v=' . $version;
    }

    /**
     * Fetch a key from environment configuration variables.
     *
     * @param string $key The key name of the environment variable.
     * @param string $default The fallback value if key does not exist.
     * @return string The configuration value or its default fallback.
     */
    public function env(string $key, string $default = ''): string
    {
        return (string) (getenv($key) ?: $default);
    }

    /**
     * Execute an action hook and capture all output buffer content.
     *
     * Enforces enterprise script-injection filters to sanitize plugin outputs,
     * mitigating cross-site scripting (XSS) risks from untrusted extension hooks.
     *
     * @param string $hookName The registration handle for the action event.
     * @param mixed ...$args Additional parameters forwarded to the event subscribers.
     * @return string The sanitized buffer output generated by executed hook listeners.
     */
    public function hook(string $hookName, mixed ...$args): string
    {
        if (!$this->container->has(EventManager::class)) {
            return '';
        }

        /** @var EventManager $events */
        $events = $this->container->get(EventManager::class);

        ob_start();
        $events->doAction($hookName, ...$args);
        $output = ob_get_clean() ?: '';

        /**
         * Security Mitigation (AUD-G7): Sanitizes action hook buffer output to prevent cross-site
         * scripting (XSS) from potentially compromised plugin hook registrations.
         * Strips structurally high-risk elements (e.g. script, iframe, object, form) and inline event handlers
         * while preserving safe display components like lists, anchor tags, SVG, and span containers.
         */
        if ($output !== '') {
            $output = $this->sanitizeHookOutput($output);
        }

        return $output;
    }

    /**
     * Strips structurally dangerous markup from plugin hook output.
     *
     * Trust contract: plugins are installed, sandbox-audited server-side code
     * and their hooks emit HTML by design - plugins MUST escape any user data
     * they interpolate. This filter is defense-in-depth against a compromised
     * plugin, not an escaping layer. It removes script-capable elements,
     * inline event handlers (quoted or unquoted), and javascript: URIs, and
     * repeats until the output is stable so split-tag tricks such as
     * <scr<script>ipt> cannot reassemble a stripped element after one pass.
     *
     * @param string $output Raw buffered hook output.
     * @return string The sanitized markup ('' when no stable fixed point is reached).
     */
    private function sanitizeHookOutput(string $output): string
    {
        for ($pass = 0; $pass < 10; $pass++) {
            $before = $output;
            $output = preg_replace(
                '/<\s*(script|iframe|object|embed|form|base|meta|link)[^>]*>.*?<\s*\/\s*\1\s*>/is',
                '',
                $output
            ) ?? $output;
            $output = preg_replace(
                '/<\s*(script|iframe|object|embed|form|base|meta|link)[^>]*\/?>/i',
                '',
                $output
            ) ?? $output;
            $output = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $output) ?? $output;
            $output = preg_replace('/(?:href|src)\s*=\s*["\']?\s*javascript:[^"\'\s>]*["\']?/i', '', $output) ?? $output;
            if ($output === $before) {
                return $output;
            }
        }

        // The markup kept mutating across passes - adversarial input. Refuse it.
        return '';
    }

    /**
     * Apply filter hook processors to a value and return the mutated result.
     *
     * @param string $hookName The target filter event registry key.
     * @param mixed $value The initial value to be transformed.
     * @param mixed ...$args Optional arguments passed to the filter chain.
     * @return mixed The processed value returned by hook subscribers.
     */
    public function hookFilter(string $hookName, mixed $value, mixed ...$args): mixed
    {
        if (!$this->container->has(EventManager::class)) {
            return $value;
        }

        /** @var EventManager $events */
        $events = $this->container->get(EventManager::class);
        return $events->applyFilter($hookName, $value, ...$args);
    }

    /**
     * Retrieve the configured application name.
     *
     * @return string The application name.
     */
    public function appName(): string
    {
        $configApp = $this->container->get('config.app');
        if (is_array($configApp) && isset($configApp['name']) && is_string($configApp['name'])) {
            return $configApp['name'];
        }
        return 'OwnPay';
    }

    /**
     * Retrieve the application version.
     *
     * @return string The core application semantic version.
     */
    public function appVersion(): string
    {
        $configApp = $this->container->get('config.app');
        if (is_array($configApp) && isset($configApp['version']) && is_string($configApp['version'])) {
            return $configApp['version'];
        }
        return '0.1.0';
    }

    /**
     * Retrieve a runtime setting value.
     *
     * @param string $key The configuration key.
     * @param string $default The fallback value.
     * @return string The settings value or fallback.
     */
    public function setting(string $key, string $default = ''): string
    {
        $settings = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
        if ($settings instanceof \OwnPay\Repository\SettingsRepository) {
            $parts = explode('.', $key);
            $group = $parts[0] !== '' ? $parts[0] : 'general';
            $name = $parts[1] ?? '';

            $brandCtx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandId = $brandCtx->getActiveBrandId();
                if ($brandId !== null && $brandId > 0) {
                    $val = $settings->getScoped($group, $name, $brandId, $default);
                    return is_string($val) ? $val : $default;
                }
            }

            $val = $settings->get($group, $name, $default);
            return is_string($val) ? $val : $default;
        }
        return $default;
    }

    /**
     * Retrieve and subsequently clear all flash messages queued in the current session.
     *
     * @return array<string, string[]> An array of flash messages structured by level (e.g. success, error).
     */
    public function flashMessages(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        if (!is_array($flash)) {
            return [];
        }
        $validated = [];
        foreach ($flash as $key => $messages) {
            if (is_string($key) && is_array($messages)) {
                $msgList = [];
                foreach ($messages as $msg) {
                    if (is_string($msg)) {
                        $msgList[] = $msg;
                    }
                }
                $validated[$key] = $msgList;
            }
        }
        return $validated;
    }

    /**
     * Format a scalar amount representation into a localized currency string.
     *
     * @param string|int|float $amount The numerical amount value.
     * @param string $currency The ISO-4217 target currency code.
     * @param int $decimals The precision scale of decimal places.
     * @return string The formatted money output.
     */
    public function formatMoney(string|int|float $amount, string $currency = 'BDT', int $decimals = 2): string
    {
        $symbols = [
            'BDT' => 'à§³',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => 'Â£',
            'INR' => '¹',
            'JPY' => 'Â¥',
            'CNY' => 'Â¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
        ];

        $symbol = $symbols[strtoupper($currency)] ?? strtoupper($currency) . ' ';
        $formatted = number_format((float) $amount, $decimals, '.', ',');
        return $symbol . $formatted;
    }

    /**
     * Format a datetime string using a specified output layout.
     *
     * @param string|null $datetime The database datetime representation.
     * @param string $format The PHP datetime formatting template string.
     * @return string The formatted date/time string, or original fallback on error.
     */
    public function formatDatetime(?string $datetime, string $format = 'd M Y, h:i A'): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($datetime);
            return $dt->format($format);
        } catch (\Exception) {
            return $datetime;
        }
    }

    /**
     * Truncate text content to a maximum length boundary, appending an ellipsis indicator.
     *
     * @param string $text The target text block.
     * @param int $length The character threshold limit.
     * @param string $suffix The truncation suffix.
     * @return string The truncated text string.
     */
    public function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . $suffix;
    }

    /**
     * Transliterate and normalize a string into a URL-safe, hyphenated slug.
     *
     * @param string $text The source string.
     * @return string The URL-safe slug representation.
     */
    public function slugify(string $text): string
    {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text) ?: $text;
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\-]/', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;
        return trim($text, '-');
    }

    /**
     * Compute a human-readable temporal relative offset from the target datetime.
     *
     * @param string|null $datetime The database datetime string.
     * @return string A human-friendly "time ago" string.
     */
    public function timeAgo(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($datetime);
            $now = new \DateTimeImmutable();
            $diff = $now->getTimestamp() - $dt->getTimestamp();

            if ($diff < 0) {
                return 'just now';
            }
            if ($diff < 60) {
                return $diff . 's ago';
            }
            if ($diff < 3600) {
                return (int) ($diff / 60) . 'm ago';
            }
            if ($diff < 86400) {
                return (int) ($diff / 3600) . 'h ago';
            }
            if ($diff < 2592000) {
                return (int) ($diff / 86400) . 'd ago';
            }
            return $dt->format('d M Y');
        } catch (\Exception) {
            return $datetime;
        }
    }

    /**
     * Format an integer byte count into a readable string (e.g. 1.2 MB).
     *
     * @param string|int|float|null $bytes The bytes count.
     * @return string The formatted byte representation.
     */
    public function formatBytes(string|int|float|null $bytes): string
    {
        $b = (float) ($bytes ?? 0);
        if ($b >= 1073741824) {
            return round($b / 1073741824, 2) . ' GB';
        }
        if ($b >= 1048576) {
            return round($b / 1048576, 2) . ' MB';
        }
        if ($b >= 1024) {
            return round($b / 1024, 1) . ' KB';
        }
        return $b . ' B';
    }
}

