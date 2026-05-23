<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\SmsGateway;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

/**
 * SMS Gateway Addon — Twilio, Vonage, custom HTTP API.
 * Hooks into sms.send to dispatch SMS via configured provider.
 * OWASP: Secrets from DB only (never logged), SSRF-safe URL validation for custom API.
 */
final class Plugin implements PluginInterface
{
    /** @var array<string, string> */
    private array $settings = [];

    public static function metadata(): array
    {
        return [
            'name'        => 'SMS Gateway',
            'slug'        => 'sms-gateway',
            'version'     => '1.0.0',
            'description' => 'Send SMS via Twilio, Vonage, or custom HTTP API.',
            'author'      => 'Own Pay',
            'type'        => 'addon',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::COMMUNICATION];
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('sms.send', [$this, 'send'], 10);
    }

    public function boot(Container $container): void
    {
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            $this->settings = $repo->getGroup('plugin.sms-gateway');
        }
    }

    public function deactivate(Container $container): void {}

    public function uninstall(Container $container): void
    {
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            $repo->deleteGroup('plugin.sms-gateway');
        }
    }

    public function fields(): array
    {
        return [
            [
                'name'    => 'provider',
                'label'   => 'SMS Provider',
                'type'    => 'select',
                'default' => 'custom',
                'options' => ['twilio' => 'Twilio', 'vonage' => 'Vonage', 'custom' => 'Custom HTTP API'],
                'help'    => 'Select your SMS provider.',
            ],
            [
                'name'    => 'twilio_sid',
                'label'   => 'Twilio Account SID',
                'type'    => 'text',
                'default' => '',
            ],
            [
                'name'    => 'twilio_token',
                'label'   => 'Twilio Auth Token',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'name'    => 'twilio_from',
                'label'   => 'Twilio From Number',
                'type'    => 'text',
                'default' => '',
                'help'    => 'Your Twilio phone number (e.g., +1234567890)',
            ],
            [
                'name'    => 'vonage_key',
                'label'   => 'Vonage API Key',
                'type'    => 'text',
                'default' => '',
            ],
            [
                'name'    => 'vonage_secret',
                'label'   => 'Vonage API Secret',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'name'    => 'vonage_from',
                'label'   => 'Vonage From Name',
                'type'    => 'text',
                'default' => 'OwnPay',
            ],
            [
                'name'    => 'custom_api_url',
                'label'   => 'Custom API URL',
                'type'    => 'text',
                'default' => '',
                'help'    => 'HTTPS endpoint for your SMS API.',
            ],
            [
                'name'    => 'custom_api_key',
                'label'   => 'Custom API Key',
                'type'    => 'password',
                'default' => '',
            ],
            [
                'name'    => 'custom_api_method',
                'label'   => 'Custom API Method',
                'type'    => 'select',
                'default' => 'POST',
                'options' => ['POST' => 'POST', 'GET' => 'GET'],
            ],
            [
                'name'    => 'custom_api_body_template',
                'label'   => 'Custom Body Template',
                'type'    => 'textarea',
                'default' => '{"to":"{{to}}","message":"{{message}}"}',
                'help'    => 'Use {{to}} and {{message}} placeholders.',
            ],
        ];
    }

    /**
     * @param array{to: string, body: string, merchant_id?: int} $payload
     */
    /**
     * @param array{to: string, body: string, merchant_id?: int} $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $to = $payload['to'];
        $body = $payload['body'];
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
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
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
        return ['success' => $httpCode >= 200 && $httpCode < 300, 'sid' => $data['sid'] ?? null];
    }

    /** @return array<string, mixed> */
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
        return ['success' => ($msg['status'] ?? '1') === '0'];
    }

    /** @return array<string, mixed> */
    private function sendCustom(string $to, string $body): array
    {
        $url = $this->settings['custom_api_url'] ?? '';
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
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => $httpCode >= 200 && $httpCode < 300];
    }
}
