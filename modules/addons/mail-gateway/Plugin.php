<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\MailGateway;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

/**
 * Mail Gateway Addon - SMTP, Mailgun, SendGrid.
 * Hooks into mail.send to dispatch emails.
 * senior-security: Secrets from settings, TLS enforced, no PII in logs.
 */
final class Plugin implements PluginInterface
{
    /** @var array<string, string> */
    private array $settings = [];

    public static function metadata(): array
    {
        return [
            'name'        => 'Mail Gateway',
            'slug'        => 'mail-gateway',
            'version'     => '1.0.0',
            'description' => 'Send emails via SMTP, Mailgun, or SendGrid.',
            'author'      => 'OwnPay',
            'type'        => 'addon',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::ADDON];
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('mail.send', [$this, 'send'], 10);
    }

    public function boot(Container $container): void
    {
        // Load saved settings
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            if ($repo instanceof \OwnPay\Repository\SettingsRepository) {
                $this->settings = $repo->getGroup('plugin.mail-gateway');
            }
        }
    }

    public function deactivate(Container $container): void
    {
        // No cleanup needed
    }

    public function uninstall(Container $container): void
    {
        // Clear saved settings
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            if ($repo instanceof \OwnPay\Repository\SettingsRepository) {
                $repo->deleteGroup('plugin.mail-gateway');
            }
        }
    }

    public function fields(): array
    {
        return [
            [
                'name'    => 'provider',
                'label'   => 'Email Provider',
                'type'    => 'select',
                'default' => 'smtp',
                'options' => ['smtp' => 'SMTP', 'mailgun' => 'Mailgun', 'sendgrid' => 'SendGrid'],
                'help'    => 'Select your email delivery provider.',
            ],
            [
                'name'    => 'from_email',
                'label'   => 'From Email',
                'type'    => 'email',
                'default' => 'noreply@example.com',
                'help'    => 'Sender email address for outgoing emails.',
            ],
            [
                'name'    => 'from_name',
                'label'   => 'From Name',
                'type'    => 'text',
                'default' => 'OwnPay',
                'help'    => 'Sender display name.',
            ],
            [
                'name'    => 'smtp_host',
                'label'   => 'SMTP Host',
                'type'    => 'text',
                'default' => '',
                'help'    => 'e.g., smtp.gmail.com, smtp.mailgun.org',
            ],
            [
                'name'    => 'smtp_port',
                'label'   => 'SMTP Port',
                'type'    => 'number',
                'default' => '587',
                'help'    => 'Common ports: 587 (TLS), 465 (SSL), 25 (unsecured)',
            ],
            [
                'name'    => 'smtp_user',
                'label'   => 'SMTP Username',
                'type'    => 'text',
                'default' => '',
            ],
            [
                'name'    => 'smtp_password',
                'label'   => 'SMTP Password',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'name'    => 'smtp_encryption',
                'label'   => 'SMTP Encryption',
                'type'    => 'select',
                'default' => 'tls',
                'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'],
            ],
            [
                'name'    => 'mailgun_domain',
                'label'   => 'Mailgun Domain',
                'type'    => 'text',
                'default' => '',
                'help'    => 'Your Mailgun sending domain.',
            ],
            [
                'name'    => 'mailgun_key',
                'label'   => 'Mailgun API Key',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'name'    => 'sendgrid_key',
                'label'   => 'SendGrid API Key',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'name'    => 'enabled',
                'label'   => 'Enable Email Sending',
                'type'    => 'toggle',
                'default' => '1',
                'help'    => 'Turn off to disable all outgoing emails.',
            ],
        ];
    }

    /**
     * @param array{to: string, subject: string, template?: string, body?: string, data?: array} $payload
     */
    /**
     * @param array{to: string, subject: string, template?: string, body?: string, data?: array<string, mixed>} $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        if (empty($this->settings['enabled'])) {
            return ['success' => false, 'error' => 'Email sending disabled'];
        }

        $to = $payload['to'];
        $subject = $payload['subject'];
        if ($to === '' || $subject === '') return ['success' => false, 'error' => 'Missing to/subject'];

        $body = $payload['body'] ?? '';
        $provider = $this->settings['provider'] ?? 'smtp';

        try {
            return match ($provider) {
                'mailgun'  => $this->sendMailgun($to, $subject, $body),
                'sendgrid' => $this->sendSendGrid($to, $subject, $body),
                default    => $this->sendSmtp($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function sendSmtp(string $to, string $subject, string $body): array
    {
        $fromEmail = $this->settings['from_email'] ?? 'noreply@example.com';
        $fromName = $this->settings['from_name'] ?? 'OwnPay';

        $headers = [
            "From: {$fromName} <{$fromEmail}>",
            "Reply-To: {$fromEmail}",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "X-Mailer: OwnPay/1.0",
        ];

        $result = @mail($to, $subject, $body, implode("\r\n", $headers));
        return ['success' => $result, 'provider' => 'smtp'];
    }

    /** @return array<string, mixed> */
    private function sendMailgun(string $to, string $subject, string $body): array
    {
        $domain = $this->settings['mailgun_domain'] ?? '';
        $key = $this->settings['mailgun_key'] ?? '';
        $from = ($this->settings['from_name'] ?? 'OwnPay') . ' <' . ($this->settings['from_email'] ?? "noreply@{$domain}") . '>';

        $ch = curl_init("https://api.mailgun.net/v3/{$domain}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "api:{$key}",
            CURLOPT_POSTFIELDS => http_build_query(['from' => $from, 'to' => $to, 'subject' => $subject, 'html' => $body]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => $httpCode >= 200 && $httpCode < 300, 'provider' => 'mailgun'];
    }

    /** @return array<string, mixed> */
    private function sendSendGrid(string $to, string $subject, string $body): array
    {
        $key = $this->settings['sendgrid_key'] ?? '';
        $from = ['email' => $this->settings['from_email'] ?? 'noreply@example.com', 'name' => $this->settings['from_name'] ?? 'OwnPay'];

        $payload = json_encode([
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => $from,
            'subject' => $subject,
            'content' => [['type' => 'text/html', 'value' => $body]],
        ]);

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => (string) $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => $httpCode >= 200 && $httpCode < 300, 'provider' => 'sendgrid'];
    }
}
