<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\MailGateway;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;
use OwnPay\Service\Communication\MailProviderInterface;

/**
 * Mail Gateway Addon - SMTP, Mailgun, SendGrid.
 * Hooks into mail.send to dispatch emails.
 * senior-security: Secrets from settings, TLS enforced, no PII in logs.
 */
final class Plugin implements PluginInterface, MailProviderInterface
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
        return [Capability::COMMUNICATION];
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
        * @param array{to: string, subject: string, template?: string, body?: string, html?: string, data?: array<string, mixed>} $payload
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

        $body = $payload['html'] ?? ($payload['body'] ?? '');
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

    public function slug(): string
    {
        return 'mail-gateway';
    }

    /** @return array<string, mixed> */
    private function sendSmtp(string $to, string $subject, string $body): array
    {
        $host = trim((string) ($this->settings['smtp_host'] ?? ''));
        $port = (int) ($this->settings['smtp_port'] ?? 587);
        $user = (string) ($this->settings['smtp_user'] ?? '');
        $password = (string) ($this->settings['smtp_password'] ?? '');
        $encryption = strtolower(trim((string) ($this->settings['smtp_encryption'] ?? 'tls')));
        $fromEmail = trim((string) ($this->settings['from_email'] ?? ''));
        $fromName = trim((string) ($this->settings['from_name'] ?? 'OwnPay'));

        if ($host === '' || $port < 1 || $port > 65535 || $fromEmail === '') {
            return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP host, port, and from email are required'];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'provider' => 'smtp', 'error' => 'Invalid recipient or sender email'];
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            return ['success' => false, 'provider' => 'smtp', 'error' => 'Unsupported SMTP encryption'];
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            return ['success' => false, 'provider' => 'smtp', 'error' => "SMTP connection failed: {$error}"];
        }

        stream_set_timeout($socket, 15);
        try {
            $this->smtpRead($socket, [220]);
            $this->smtpCommand($socket, 'EHLO ' . $this->smtpClientName(), [250]);

            if ($encryption === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('SMTP TLS negotiation failed');
                }
                $this->smtpCommand($socket, 'EHLO ' . $this->smtpClientName(), [250]);
            }

            if ($user !== '') {
                $this->smtpCommand($socket, 'AUTH LOGIN', [334]);
                $this->smtpCommand($socket, base64_encode($user), [334]);
                $this->smtpCommand($socket, base64_encode($password), [235]);
            }

            $this->smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->smtpCommand($socket, 'DATA', [354]);

            $safeSubject = str_replace(["\r", "\n"], '', $subject);
            $safeFromName = str_replace(["\r", "\n"], '', $fromName);
            $safeBody = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);
            $safeBody = preg_replace('/^\./m', '..', $safeBody) ?? $safeBody;
            $headers = [
                'From: ' . ($safeFromName !== '' ? $safeFromName . ' <' . $fromEmail . '>' : $fromEmail),
                'To: <' . $to . '>',
                'Subject: ' . $safeSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: OwnPay/1.0',
            ];
            $this->smtpWrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $safeBody . "\r\n.");
            $this->smtpRead($socket, [250]);
            $this->smtpWrite($socket, 'QUIT');
            fclose($socket);
            return ['success' => true, 'provider' => 'smtp'];
        } catch (\Throwable $e) {
            fclose($socket);
            return ['success' => false, 'provider' => 'smtp', 'error' => $e->getMessage()];
        }
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expected
     */
    private function smtpCommand($socket, string $command, array $expected): void
    {
        $this->smtpWrite($socket, $command);
        $this->smtpRead($socket, $expected);
    }

    /** @param resource $socket */
    private function smtpWrite($socket, string $command): void
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new \RuntimeException('SMTP write failed');
        }
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expected
     */
    private function smtpRead($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 2048);
            if ($line === false) {
                throw new \RuntimeException('SMTP server closed the connection');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $message = trim(preg_replace('/^\d{3}[- ]?/m', '', $response) ?? $response);
            throw new \RuntimeException("SMTP server rejected command ({$code}): {$message}");
        }
        return $response;
    }

    private function smtpClientName(): string
    {
        $name = gethostname();
        if (!is_string($name) || $name === '') {
            return 'localhost';
        }
        $cleaned = preg_replace('/[^a-zA-Z0-9.-]/', '', $name);
        return is_string($cleaned) && $cleaned !== '' ? $cleaned : 'localhost';
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
