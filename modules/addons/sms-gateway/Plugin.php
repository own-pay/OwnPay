<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\SmsGateway;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;
use OwnPay\Core\Logger;

/**
 * SMS Gateway Addon — Twilio, Vonage, custom HTTP API.
 * Hooks into sms.send to dispatch SMS via configured provider.
 * OWASP: Secrets from DB only (never logged), SSRF-safe URL validation for custom API.
 */
final class Plugin implements PluginInterface
{
    private array $settings = [];
    private ?Logger $logger = null;

    public function register(EventManager $events): void
    {
        $events->addAction('sms.send', [$this, 'send'], 10);
    }

    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Send SMS via configured provider.
     * @param array{to: string, body: string, merchant_id?: int} $payload
     */
    public function send(array $payload): array
    {
        $to = $payload['to'] ?? '';
        $body = $payload['body'] ?? '';
        if ($to === '' || $body === '') {
            return ['success' => false, 'error' => 'Missing to/body'];
        }

        $provider = $this->settings['provider'] ?? 'custom';

        try {
            return match ($provider) {
                'twilio'  => $this->sendTwilio($to, $body),
                'vonage'  => $this->sendVonage($to, $body),
                default   => $this->sendCustom($to, $body),
            };
        } catch (\Throwable $e) {
            $this->logger?->error('SMS send failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendTwilio(string $to, string $body): array
    {
        $sid = $this->settings['twilio_sid'] ?? '';
        $token = $this->settings['twilio_token'] ?? '';
        $from = $this->settings['twilio_from'] ?? '';

        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$sid}:{$token}",
            CURLOPT_POSTFIELDS => http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'sid'     => $data['sid'] ?? null,
            'status'  => $data['status'] ?? 'unknown',
        ];
    }

    private function sendVonage(string $to, string $body): array
    {
        $key = $this->settings['vonage_key'] ?? '';
        $secret = $this->settings['vonage_secret'] ?? '';
        $from = $this->settings['vonage_from'] ?? 'OwnPay';

        $ch = curl_init('https://rest.nexmo.com/sms/json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['api_key' => $key, 'api_secret' => $secret, 'from' => $from, 'to' => $to, 'text' => $body]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        $msg = $data['messages'][0] ?? [];
        return [
            'success' => ($msg['status'] ?? '1') === '0',
            'message_id' => $msg['message-id'] ?? null,
        ];
    }

    private function sendCustom(string $to, string $body): array
    {
        $url = $this->settings['custom_api_url'] ?? '';
        // OWASP/SSRF: Validate URL — must be HTTPS, no internal IPs
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return ['success' => false, 'error' => 'Custom API URL must be HTTPS'];
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|localhost)/i', $host)) {
            return ['success' => false, 'error' => 'Internal URLs blocked (SSRF)'];
        }

        $method = strtoupper($this->settings['custom_api_method'] ?? 'POST');
        $bodyTemplate = $this->settings['custom_api_body_template'] ?? '{"to":"{{to}}","message":"{{message}}"}';
        $rendered = str_replace(['{{to}}', '{{message}}'], [$to, $body], $bodyTemplate);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $rendered,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($this->settings['custom_api_key'] ?? ''),
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'response' => json_decode((string) $response, true),
        ];
    }

    public function getInfo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/manifest.json'), true) ?: [];
    }
}
