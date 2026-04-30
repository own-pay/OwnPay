<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

use OwnPay\Event\EventManager;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\CommLogRepository;
use OwnPay\Repository\SettingsRepository;

/**
 * Communication service — unified dispatch for SMS, email, and plugin channels.
 *
 * Auto-discovers communication plugins via Capability::COMMUNICATION.
 * Fires: communication.sms.send, communication.mail.send, communication.channels, communication.template.render
 */
final class CommunicationService
{
    private PluginRegistry $plugins;
    private EventManager $events;
    private CommLogRepository $commLog;
    private SettingsRepository $settings;

    public function __construct(
        PluginRegistry $plugins,
        EventManager $events,
        CommLogRepository $commLog,
        SettingsRepository $settings
    ) {
        $this->plugins = $plugins;
        $this->events = $events;
        $this->commLog = $commLog;
        $this->settings = $settings;
    }

    /**
     * Send SMS via configured provider.
     */
    public function sendSms(int $merchantId, string $to, string $message): array
    {
        $provider = $this->resolveProvider('sms', $merchantId);
        if ($provider === null) {
            return ['success' => false, 'error' => 'No SMS provider configured'];
        }

        /** @var SmsProviderInterface $provider */
        $logId = $this->commLog->log($merchantId, 'sms', $to, 'sms.send', $message, $provider->slug(), 'queued');

        try {
            $result = $provider->send($to, $message);

            if ($result['success']) {
                $this->commLog->markSent((int) $logId);
            } else {
                $this->commLog->markFailed((int) $logId, $result['error'] ?? 'Unknown error');
            }

            $this->events->doAction('communication.sms.send', $merchantId, $to, $result);
            return $result;

        } catch (\Throwable $e) {
            $this->commLog->markFailed((int) $logId, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send email via configured provider.
     */
    public function sendEmail(int $merchantId, array $message): array
    {
        $provider = $this->resolveProvider('mail', $merchantId);
        if ($provider === null) {
            // Fallback to PHP mail()
            return $this->fallbackMail($message);
        }

        /** @var MailProviderInterface $provider */
        $logId = $this->commLog->log(
            $merchantId, 'email', $message['to'] ?? '', 'mail.send',
            $message['subject'] ?? '', $provider->slug(), 'queued'
        );

        try {
            $result = $provider->send($message);

            if ($result['success']) {
                $this->commLog->markSent((int) $logId);
            } else {
                $this->commLog->markFailed((int) $logId, $result['error'] ?? 'Unknown error');
            }

            $this->events->doAction('communication.mail.send', $merchantId, $message, $result);
            return $result;

        } catch (\Throwable $e) {
            $this->commLog->markFailed((int) $logId, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Render message template with variables.
     */
    public function renderTemplate(string $template, array $vars): string
    {
        $rendered = $template;
        foreach ($vars as $key => $value) {
            $rendered = str_replace('{{' . $key . '}}', (string) $value, $rendered);
        }
        return $this->events->applyFilter('communication.template.render', $rendered, $vars);
    }

    /**
     * Get available communication channels.
     * @return string[]
     */
    public function availableChannels(): array
    {
        $channels = ['admin']; // Always available

        $commPlugins = $this->plugins->withCapability(Capability::COMMUNICATION);
        foreach ($commPlugins as $slug => $plugin) {
            $caps = $plugin->capabilities();
            $channels[] = $slug;
        }

        return $this->events->applyFilter('communication.channels', $channels);
    }

    /**
     * Resolve provider for type from communication plugins.
     */
    private function resolveProvider(string $type, int $merchantId): mixed
    {
        $preferredSlug = $this->settings->get('communication', "{$type}_provider", '');
        $commPlugins = $this->plugins->withCapability(Capability::COMMUNICATION);

        // Try preferred first
        if ($preferredSlug !== '' && isset($commPlugins[$preferredSlug])) {
            return $commPlugins[$preferredSlug];
        }

        // Fallback to first available
        foreach ($commPlugins as $plugin) {
            $iface = match ($type) {
                'sms' => SmsProviderInterface::class,
                'mail' => MailProviderInterface::class,
                default => null,
            };
            if ($iface !== null && $plugin instanceof $iface) {
                return $plugin;
            }
        }

        return null;
    }

    private function fallbackMail(array $message): array
    {
        $to = $message['to'] ?? '';
        $subject = $message['subject'] ?? '';
        $body = $message['html'] ?? $message['body'] ?? '';
        $headers = "Content-Type: text/html; charset=UTF-8\r\n";

        if (!empty($message['from'])) {
            $headers .= "From: {$message['from']}\r\n";
        }

        $sent = @mail($to, $subject, $body, $headers);
        return ['success' => $sent, 'error' => $sent ? null : 'PHP mail() failed'];
    }
}
