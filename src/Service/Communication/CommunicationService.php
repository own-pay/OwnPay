<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

use OwnPay\Event\EventManager;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\CommLogRepository;
use OwnPay\Repository\SettingsRepository;

/**
 * OwnPay Communication Service.
 *
 * Provides a unified message delivery router for sending transactional SMS notifications
 * and email confirmations, resolving and dispatching messages via external channel plugins
 * (auto-discovered through Capability::COMMUNICATION) with transparent database audit logging.
 *
 * @package OwnPay\Service\Communication
 */
final class CommunicationService
{
    /**
     * @var PluginRegistry The plugin system discovery index registry.
     */
    private PluginRegistry $plugins;

    /**
     * @var EventManager Global event manager hook/filter dispatcher.
     */
    private EventManager $events;

    /**
     * @var CommLogRepository The repository auditing dispatch events and payload logs.
     */
    private CommLogRepository $commLog;

    /**
     * @var SettingsRepository The repository managing system settings.
     */
    private SettingsRepository $settings;

    /**
     * CommunicationService constructor.
     *
     * @param PluginRegistry $plugins Plugin system registry.
     * @param EventManager $events Hook/filter event manager.
     * @param CommLogRepository $commLog Audit logger for communications.
     * @param SettingsRepository $settings Settings parameters gateway.
     */
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
     * Transmits a text SMS message through the configured merchant SMS plugin.
     *
     * @param int $merchantId The primary identifier of the brand/merchant context.
     * @param string $to Recipient target telephone number.
     * @param string $message Text content payload.
     * @return array{success: bool, error?: string|null} Execution status results.
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
     * Transmits an email notification through the configured merchant SMTP/API plugin.
     *
     * Falls back to local PHP mail() transport if no external plugin is active.
     *
     * @param int $merchantId The primary identifier of the brand/merchant context.
     * @param array{to: string, subject: string, body: string, html?: string, from?: string, reply_to?: string, attachments?: array<int, array<string, mixed>>} $message Email payload.
     * @return array{success: bool, error?: string|null} Execution status results.
     */
    public function sendEmail(int $merchantId, array $message): array
    {
        if (empty($message['from'])) {
            $fromAddress = trim((string) $this->settings->getScoped('general', 'mail_from_email', $merchantId, ''));
            if ($fromAddress !== '') {
                $fromName = trim((string) $this->settings->getScoped('general', 'mail_from_name', $merchantId, ''));
                $message['from'] = $fromName !== ''
                    ? sprintf('%s <%s>', $fromName, $fromAddress)
                    : $fromAddress;
            }
        }

        $provider = $this->resolveProvider('mail', $merchantId);
        if ($provider === null) {
            return $this->fallbackMail($message);
        }

        /** @var MailProviderInterface $provider */
        $logId = $this->commLog->log(
            $merchantId, 'email', $message['to'], 'mail.send',
            $message['subject'], $provider->slug(), 'queued'
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
     * Renders a messaging template with contextual placeholder variables.
     *
     * Triggers the 'communication.template.render' filter to let plugins post-process templates.
     *
     * @param string $template Plain text or HTML template content.
     * @param array<string, mixed> $vars Variables to replace.
     * @return string Fully compiled message payload.
     */
    public function renderTemplate(string $template, array $vars): string
    {
        $rendered = $template;
        foreach ($vars as $key => $value) {
            $valStr = is_scalar($value) ? (string) $value : '';
            $rendered = str_replace('{{' . $key . '}}', $valStr, $rendered);
        }
        $res = $this->events->applyFilter('communication.template.render', $rendered, $vars);
        return is_scalar($res) ? (string) $res : $rendered;
    }

    /**
     * Retrieves lists of registered and active communication slugs.
     *
     * @return string[] Unique communication channel slugs.
     */
    public function availableChannels(): array
    {
        $channels = ['admin'];

        $commPlugins = $this->plugins->withCapability(Capability::COMMUNICATION);
        foreach ($commPlugins as $slug => $plugin) {
            $channels[] = $slug;
        }

        $res = $this->events->applyFilter('communication.channels', $channels);
        if (is_array($res)) {
            $out = [];
            foreach ($res as $item) {
                if (is_string($item)) {
                    $out[] = $item;
                }
            }
            return $out;
        }
        return $channels;
    }

    /**
     * Resolves the active driver instance registered for the communication capability type.
     *
     * Checks user settings preferences before defaulting to the first discovered plugin.
     *
     * @param string $type The media type provider ('sms' or 'mail').
     * @param int $merchantId The merchant primary ID context.
     * @return mixed Resolved plugin instance implementing matching interfaces, or null.
     */
    private function resolveProvider(string $type, int $merchantId): mixed
    {
        $preferredSlug = $this->settings->get('communication', "{$type}_provider", '');
        $commPlugins = $this->plugins->withCapability(Capability::COMMUNICATION);

        if ($preferredSlug !== '' && isset($commPlugins[$preferredSlug])) {
            return $commPlugins[$preferredSlug];
        }

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

    /**
     * Internal fallback using php mail() when no SMTP/API plugins are registered.
     *
     * @param array{to: string, subject: string, body: string, html?: string, from?: string, reply_to?: string, attachments?: array<int, array<string, mixed>>} $message Email details.
     * @return array{success: bool, error: string|null} Status vector.
     */
    private function fallbackMail(array $message): array
    {
        $to = $message['to'];
        $subject = $message['subject'];
        $body = $message['html'] ?? $message['body'];
        $headers = "Content-Type: text/html; charset=UTF-8\r\n";

        if (!empty($message['from'])) {
            $headers .= "From: {$message['from']}\r\n";
        }

        $sent = @mail($to, $subject, $body, $headers);
        return ['success' => $sent, 'error' => $sent ? null : 'PHP mail() failed'];
    }
}
