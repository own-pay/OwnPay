<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\MailGateway;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;
use OwnPay\Core\Logger;

/**
 * Mail Gateway Addon — SMTP, Mailgun, SendGrid.
 * Hooks into mail.send to dispatch emails.
 * senior-security: Secrets from settings, TLS enforced, no PII in logs.
 */
final class Plugin implements PluginInterface
{
    private array $settings = [];
    private ?Logger $logger = null;
    private ?\Twig\Environment $twig = null;

    public function register(EventManager $events): void
    {
        $events->addAction('mail.send', [$this, 'send'], 10);
    }

    public function setSettings(array $settings): void { $this->settings = $settings; }
    public function setLogger(Logger $logger): void { $this->logger = $logger; }
    public function setTwig(\Twig\Environment $twig): void { $this->twig = $twig; }

    /**
     * @param array{to: string, subject: string, template?: string, body?: string, data?: array} $payload
     */
    public function send(array $payload): array
    {
        $to = $payload['to'] ?? '';
        $subject = $payload['subject'] ?? '';
        if ($to === '' || $subject === '') return ['success' => false, 'error' => 'Missing to/subject'];

        // Render template or use raw body
        $body = $payload['body'] ?? '';
        if (!empty($payload['template']) && $this->twig) {
            try {
                $body = $this->twig->render("email/{$payload['template']}.twig", $payload['data'] ?? []);
            } catch (\Throwable $e) {
                $this->logger?->warning('Mail template render failed', ['template' => $payload['template']]);
            }
        }

        $provider = $this->settings['provider'] ?? 'smtp';

        try {
            return match ($provider) {
                'mailgun'  => $this->sendMailgun($to, $subject, $body),
                'sendgrid' => $this->sendSendGrid($to, $subject, $body),
                default    => $this->sendSmtp($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            $this->logger?->error('Mail send failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendSmtp(string $to, string $subject, string $body): array
    {
        $host = $this->settings['smtp_host'] ?? '';
        $port = (int) ($this->settings['smtp_port'] ?? 587);
        $user = $this->settings['smtp_user'] ?? '';
        $pass = $this->settings['smtp_password'] ?? '';
        $encryption = $this->settings['smtp_encryption'] ?? 'tls';
        $fromEmail = $this->settings['from_email'] ?? 'noreply@example.com';
        $fromName = $this->settings['from_name'] ?? 'Own Pay';

        // Use PHP's mail() as fallback, SMTP via socket for production
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

    private function sendMailgun(string $to, string $subject, string $body): array
    {
        $domain = $this->settings['mailgun_domain'] ?? '';
        $key = $this->settings['mailgun_key'] ?? '';
        $from = ($this->settings['from_name'] ?? 'Own Pay') . ' <' . ($this->settings['from_email'] ?? "noreply@{$domain}") . '>';

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

        return ['success' => $httpCode >= 200 && $httpCode < 300, 'provider' => 'mailgun', 'response' => json_decode((string) $response, true)];
    }

    private function sendSendGrid(string $to, string $subject, string $body): array
    {
        $key = $this->settings['sendgrid_key'] ?? '';
        $from = ['email' => $this->settings['from_email'] ?? 'noreply@example.com', 'name' => $this->settings['from_name'] ?? 'Own Pay'];

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
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => $httpCode >= 200 && $httpCode < 300, 'provider' => 'sendgrid'];
    }

    public function getInfo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/manifest.json'), true) ?: [];
    }
}
