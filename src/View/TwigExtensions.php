<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Core Twig extensions for Own Pay.
 *
 * Provides template functions and filters:
 *   Functions: csrf_token(), asset(), route(), env(), hook(), hook_filter(), app_name(), app_version()
 *   Filters:   money(), datetime(), truncate(), slug()
 */
final class TwigExtensions extends AbstractExtension
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getName(): string
    {
        return 'ownpay';
    }

    // ─── Functions ─────────────────────────────────────────────

    /**
     * @return TwigFunction[]
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

    // ─── Filters ───────────────────────────────────────────────

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', [$this, 'formatMoney']),
            new TwigFilter('datetime', [$this, 'formatDatetime']),
            new TwigFilter('truncate', [$this, 'truncate']),
            new TwigFilter('slug', [$this, 'slugify']),
            new TwigFilter('time_ago', [$this, 'timeAgo']),
        ];
    }

    // ─── Function Implementations ──────────────────────────────

    /**
     * Get current CSRF token value.
     */
    public function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Output a hidden CSRF input field.
     */
    public function csrfField(): string
    {
        $token = $this->csrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate versioned asset URL.
     * Example: {{ asset('css/admin.css') }} → /assets/css/admin.css?v=0.1.0
     */
    public function asset(string $path): string
    {
        $version = $this->container->get('config.app')['version'] ?? '0.1.0';
        $cleanPath = ltrim($path, '/');
        return '/assets/' . $cleanPath . '?v=' . $version;
    }

    /**
     * Get an environment variable.
     */
    public function env(string $key, string $default = ''): string
    {
        return (string) (getenv($key) ?: $default);
    }

    /**
     * Fire an action hook and capture any output.
     * Usage: {{ hook('admin.head') }}
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
        return ob_get_clean() ?: '';
    }

    /**
     * Apply a filter hook and return the result.
     * Usage: {{ hook_filter('admin.dashboard.stats', stats) }}
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
     * Get application name.
     */
    public function appName(): string
    {
        return $this->container->get('config.app')['name'] ?? 'Own Pay';
    }

    /**
     * Get application version.
     */
    public function appVersion(): string
    {
        return $this->container->get('config.app')['version'] ?? '0.1.0';
    }

    /**
     * Get a system setting value (from DB via cache).
     * Placeholder — will be wired to SettingsRepository in Phase E.
     */
    public function setting(string $key, string $default = ''): string
    {
        // Phase E will provide SettingsService; for now return default
        return $default;
    }

    /**
     * Get and clear flash messages from session.
     *
     * @return array<string, string[]>
     */
    public function flashMessages(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    // ─── Filter Implementations ────────────────────────────────

    /**
     * Format a number as currency.
     * Usage: {{ amount|money('BDT') }} → ৳1,234.56
     *        {{ amount|money('USD') }} → $1,234.56
     */
    public function formatMoney(string|int|float $amount, string $currency = 'BDT', int $decimals = 2): string
    {
        $symbols = [
            'BDT' => '৳',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'INR' => '₹',
            'JPY' => '¥',
            'CNY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
        ];

        $symbol = $symbols[strtoupper($currency)] ?? strtoupper($currency) . ' ';
        $formatted = number_format((float) $amount, $decimals, '.', ',');
        return $symbol . $formatted;
    }

    /**
     * Format a datetime string.
     * Usage: {{ created_at|datetime('d M Y, h:i A') }}
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
     * Truncate a string to a max length with ellipsis.
     * Usage: {{ description|truncate(100) }}
     */
    public function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . $suffix;
    }

    /**
     * Convert a string to URL-safe slug.
     * Usage: {{ name|slug }}
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
     * Human-readable time ago.
     * Usage: {{ created_at|time_ago }}
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
}
