<?php

declare(strict_types=1);

namespace OwnPayPlugin\WebhookLogger;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;
use OwnPay\Core\Database;

/**
 * Webhook Logger — Real-world reference plugin.
 *
 * Demonstrates:
 *   - Hooking into multiple action events
 *   - Using database read/write capabilities
 *   - Declaring settings fields
 *   - Running database migrations (up + down)
 *   - Admin menu registration via manifest
 *   - Clean activate/deactivate/uninstall lifecycle
 */
class Plugin implements PluginInterface
{
    private const SLUG = 'webhook-logger';

    // ── Lifecycle ──────────────────────────────────────────────────

    public function register(EventManager $events): void
    {
        $events->addAction('payment.gateway.webhook', [$this, 'onWebhook'], owner: self::SLUG);
        $events->addAction('payment.transaction.created', [$this, 'onTransactionCreated'], owner: self::SLUG);
        $events->addAction('payment.transaction.completed', [$this, 'onTransactionCompleted'], owner: self::SLUG);
    }

    public function boot(): void
    {
        // No post-registration setup needed.
    }

    public function activate(): void
    {
        // Migrations are run by PluginMigrator before this method is called.
        // Set default settings.
        if (function_exists('get_env') && get_env(self::SLUG . '-enabled', 'both') === '') {
            if (function_exists('set_env')) {
                set_env(self::SLUG . '-enabled', 'yes');
                set_env(self::SLUG . '-max_payload_size', '65535');
                set_env(self::SLUG . '-retention_days', '30');
            }
        }
    }

    public function deactivate(): void
    {
        // Data is preserved on deactivation — only hooks are unregistered
        // (handled by EventManager::removeAllByOwner in PluginLoader).
    }

    public function uninstall(): void
    {
        // Migrations are rolled back by PluginMigrator before this.
        // Clean up settings from op_env.
        if (function_exists('set_env')) {
            set_env(self::SLUG . '-enabled', '');
            set_env(self::SLUG . '-max_payload_size', '');
            set_env(self::SLUG . '-retention_days', '');
        }
    }

    // ── Plugin Info ────────────────────────────────────────────────

    public function info(): array
    {
        return [
            'title'       => 'Webhook Logger',
            'description' => 'Logs incoming webhook events for debugging and auditing',
            'version'     => '1.0.0',
        ];
    }

    public function fields(): array
    {
        return [
            [
                'name'     => 'enabled',
                'label'    => 'Enable Logging',
                'type'     => 'select',
                'options'  => ['yes' => 'Yes', 'no' => 'No'],
                'value'    => 'yes',
                'required' => true,
            ],
            [
                'name'  => 'max_payload_size',
                'label' => 'Max Payload Size (bytes)',
                'type'  => 'number',
                'value' => '65535',
            ],
            [
                'name'  => 'retention_days',
                'label' => 'Log Retention (days)',
                'type'  => 'number',
                'value' => '30',
            ],
        ];
    }

    // ── Hook Handlers ──────────────────────────────────────────────

    /**
     * Log an incoming gateway webhook event.
     */
    public function onWebhook(array $data = []): void
    {
        $this->logEvent('payment.gateway.webhook', $data, $data['gateway'] ?? 'unknown');
    }

    /**
     * Log a transaction creation event.
     */
    public function onTransactionCreated(array $data = []): void
    {
        $this->logEvent('payment.transaction.created', $data, 'system');
    }

    /**
     * Log a transaction completion event.
     */
    public function onTransactionCompleted(array $data = []): void
    {
        $this->logEvent('payment.transaction.completed', $data, 'system');
    }

    // ── Internal ───────────────────────────────────────────────────

    /**
     * Write a log entry to the webhook_log table.
     */
    private function logEvent(string $eventType, array $payload, string $source): void
    {
        // Check if logging is enabled
        if (function_exists('get_env') && get_env(self::SLUG . '-enabled', 'both') === 'no') {
            return;
        }

        try {
            $db = Database::getInstance();
            $prefix = $_ENV['DB_PREFIX'] ?? 'op_';

            // Truncate payload if too large
            $maxSize = 65535;
            if (function_exists('get_env')) {
                $configMax = get_env(self::SLUG . '-max_payload_size', 'both');
                if ($configMax !== '' && is_numeric($configMax)) {
                    $maxSize = (int) $configMax;
                }
            }

            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($payloadJson !== false && strlen($payloadJson) > $maxSize) {
                $payloadJson = json_encode([
                    '_truncated' => true,
                    '_original_size' => strlen($payloadJson),
                    'summary' => array_keys($payload),
                ]);
            }

            // Capture request headers (sanitized)
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace('_', '-', substr($key, 5));
                    // Skip sensitive headers
                    if (in_array(strtolower($headerName), ['cookie', 'authorization'], true)) {
                        $headers[$headerName] = '[REDACTED]';
                    } else {
                        $headers[$headerName] = is_string($value) ? $value : '';
                    }
                }
            }

            $db->execute(
                "INSERT INTO `{$prefix}webhook_log` (event_type, source, payload, headers, ip_address, created_at)
                 VALUES (:event, :source, :payload, :headers, :ip, NOW())",
                [
                    'event'   => $eventType,
                    'source'  => $source,
                    'payload' => $payloadJson,
                    'headers' => json_encode($headers),
                    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
                ],
            );
        } catch (\Throwable $e) {
            error_log("[WebhookLogger] Failed to log event '{$eventType}': " . $e->getMessage());
        }
    }
}
