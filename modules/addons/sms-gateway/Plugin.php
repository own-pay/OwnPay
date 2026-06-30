<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\SmsGateway;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;
use OwnPay\Service\Communication\SmsProviderInterface;
use OwnPay\Service\Customer\CustomerPiiService;
use OwnPay\Service\Communication\CommunicationService;
use OwnPay\Service\Domain\DomainUrlService;
use OwnPay\Repository\InvoiceRepository;
use OwnPay\Repository\SettingsRepository;

/**
 * SMS Gateway Addon - Twilio, Vonage, custom HTTP API.
 * Hooks into sms.send to dispatch SMS via configured provider.
 * OWASP: Secrets from DB only (never logged), SSRF-safe URL validation for custom API.
 */
final class Plugin implements PluginInterface, SmsProviderInterface
{
    /** @var array<string, string> */
    private array $settings = [];

    /** @var Container|null */
    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'SMS Gateway',
            'slug'        => 'sms-gateway',
            'version'     => '1.0.0',
            'description' => 'Send SMS via Twilio, Vonage, or custom HTTP API.',
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
        $events->addAction('sms.send', [$this, 'onSmsSend'], 10);
        $events->addAction('invoice.created', [$this, 'onInvoiceCreated'], 10);
        $events->addAction('payment.transaction.completed', [$this, 'onPaymentSuccess'], 10);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
        if ($container->has(SettingsRepository::class)) {
            $repo = $container->get(SettingsRepository::class);
            if ($repo instanceof SettingsRepository) {
                $this->settings = $repo->getGroup('plugin.sms-gateway');
            }
        }
    }

    public function deactivate(Container $container): void {}

    public function uninstall(Container $container): void
    {
        if ($container->has(SettingsRepository::class)) {
            $repo = $container->get(SettingsRepository::class);
            if ($repo instanceof SettingsRepository) {
                $repo->deleteGroup('plugin.sms-gateway');
            }
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
            [
                'name'    => 'send_on_invoice_created',
                'label'   => 'Send SMS on Invoice Created',
                'type'    => 'toggle',
                'default' => '0',
            ],
            [
                'name'    => 'invoice_created_template',
                'label'   => 'Invoice Created SMS Template',
                'type'    => 'textarea',
                'default' => 'Hi {{customer}}, invoice {{invoice_number}} of {{currency}} {{due_amount}} is ready. Due date: {{due_date}}. Pay here: {{url}}',
                'help'    => 'Use {{customer}}, {{invoice_number}}, {{due_amount}}, {{currency}}, {{due_date}}, {{url}} placeholders.',
            ],
            [
                'name'    => 'send_on_payment_success',
                'label'   => 'Send SMS on Payment Success',
                'type'    => 'toggle',
                'default' => '0',
            ],
            [
                'name'    => 'payment_success_template',
                'label'   => 'Payment Success SMS Template',
                'type'    => 'textarea',
                'default' => 'Thank you {{customer}}, your payment of {{currency}} {{amount}} (Trx ID: {{trx_id}}) was successful!',
                'help'    => 'Use {{customer}}, {{amount}}, {{currency}}, {{trx_id}}, {{url}} placeholders.',
            ],
        ];
    }

    public function slug(): string
    {
        return 'sms-gateway';
    }

    /**
     * Legacy handler for the 'sms.send' action trigger.
     *
     * @param array{to: string, body: string, merchant_id?: int} $payload
     * @return array<string, mixed>
     */
    public function onSmsSend(array $payload): array
    {
        $to = $payload['to'];
        $body = $payload['body'];
        
        $midVal = $payload['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int) $midVal : null;

        $options = [];
        if ($merchantId !== null) {
            $options['merchant_id'] = $merchantId;
        }

        return $this->send($to, $body, $options);
    }

    /**
     * Event listener triggered when an invoice is successfully created.
     *
     * @param array<string, mixed> $invoice The invoice attributes.
     * @return void
     */
    public function onInvoiceCreated(array $invoice): void
    {
        $container = $this->container;
        if ($container === null) {
            return;
        }

        $midVal = $invoice['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int) $midVal : 0;
        if ($merchantId <= 0) {
            return;
        }

        $settings = $this->getSettings($merchantId);
        $enabled = ($settings['send_on_invoice_created'] ?? '0') === '1';
        if (!$enabled) {
            return;
        }

        $template = $settings['invoice_created_template'] ?? '';
        if ($template === '') {
            return;
        }

        $custVal = $invoice['customer_id'] ?? null;
        $customerId = is_numeric($custVal) ? (int) $custVal : 0;
        if ($customerId <= 0) {
            return;
        }

        try {
            $piiService = $container->get(CustomerPiiService::class);
            if (!$piiService instanceof CustomerPiiService) {
                return;
            }

            $customer = $piiService->get($merchantId, $customerId);
            if ($customer === null) {
                return;
            }

            $toVal = $customer['phone'] ?? '';
            $to = is_string($toVal) ? $toVal : '';
            if ($to === '') {
                return;
            }

            $domainUrlService = $container->get(DomainUrlService::class);
            if (!$domainUrlService instanceof DomainUrlService) {
                return;
            }

            $tokenVal = $invoice['token'] ?? '';
            $token = is_string($tokenVal) ? $tokenVal : '';
            $url = $domainUrlService->resolveBaseUrl($merchantId) . '/invoice/' . $token;

            $custNameVal = $customer['name'] ?? '';
            $customerName = is_string($custNameVal) ? $custNameVal : '';

            $invNumVal = $invoice['invoice_number'] ?? '';
            $invNum = is_string($invNumVal) ? $invNumVal : '';

            $totalVal = $invoice['total'] ?? '';
            $total = is_scalar($totalVal) ? (string) $totalVal : '0.00';

            $currVal = $invoice['currency'] ?? '';
            $currency = is_string($currVal) ? $currVal : 'BDT';

            $dueVal = $invoice['due_date'] ?? '';
            $dueDate = is_string($dueVal) ? $dueVal : '';

            // Prepare dynamic placeholders
            $vars = [
                'customer'       => $customerName,
                'invoice_number' => $invNum,
                'amount'         => $total,
                'due_amount'     => $total,
                'currency'       => $currency,
                'due_date'       => $dueDate,
                'url'            => $url,
            ];

            // Render plain text template
            $commService = $container->get(CommunicationService::class);
            if (!$commService instanceof CommunicationService) {
                return;
            }
            $rendered = $commService->renderTemplate($template, $vars);

            // Dispatch through the central sendSms mechanism
            $commService->sendSms($merchantId, $to, $rendered);

        } catch (\Throwable) {
            // Silence exceptions to prevent billing pipeline blockage
        }
    }

    /**
     * Event listener triggered when a payment transaction is successfully completed.
     *
     * @param array<string, mixed> $transaction The transaction attributes.
     * @return void
     */
    public function onPaymentSuccess(array $transaction): void
    {
        $container = $this->container;
        if ($container === null) {
            return;
        }

        $midVal = $transaction['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int) $midVal : 0;
        if ($merchantId <= 0) {
            return;
        }

        $settings = $this->getSettings($merchantId);
        $enabled = ($settings['send_on_payment_success'] ?? '0') === '1';
        if (!$enabled) {
            return;
        }

        $template = $settings['payment_success_template'] ?? '';
        if ($template === '') {
            return;
        }

        $custVal = $transaction['customer_id'] ?? null;
        $customerId = is_numeric($custVal) ? (int) $custVal : 0;
        if ($customerId <= 0) {
            return;
        }

        try {
            $piiService = $container->get(CustomerPiiService::class);
            if (!$piiService instanceof CustomerPiiService) {
                return;
            }

            $customer = $piiService->get($merchantId, $customerId);
            if ($customer === null) {
                return;
            }

            $toVal = $customer['phone'] ?? '';
            $to = is_string($toVal) ? $toVal : '';
            if ($to === '') {
                return;
            }

            $domainUrlService = $container->get(DomainUrlService::class);
            if (!$domainUrlService instanceof DomainUrlService) {
                return;
            }

            // Build matching URL: if it arose from invoice, point to invoice url, else brand base url
            $url = $domainUrlService->resolveBaseUrl($merchantId);
            
            $invIdVal = $transaction['invoice_id'] ?? null;
            $invoiceId = is_numeric($invIdVal) ? (int) $invIdVal : 0;
            if ($invoiceId > 0) {
                $invoiceRepo = $container->get(InvoiceRepository::class);
                if ($invoiceRepo instanceof InvoiceRepository) {
                    $invoice = $invoiceRepo->forTenant($merchantId)->findScoped($invoiceId);
                    if ($invoice !== null) {
                        $tokenVal = $invoice['token'] ?? '';
                        $token = is_string($tokenVal) ? $tokenVal : '';
                        $url = $domainUrlService->resolveBaseUrl($merchantId) . '/invoice/' . $token;
                    }
                }
            }

            $custNameVal = $customer['name'] ?? '';
            $customerName = is_string($custNameVal) ? $custNameVal : '';

            $amtVal = $transaction['amount'] ?? '';
            $amount = is_scalar($amtVal) ? (string) $amtVal : '0.00';

            $currVal = $transaction['currency'] ?? '';
            $currency = is_string($currVal) ? $currVal : 'BDT';

            $trxIdVal = $transaction['trx_id'] ?? '';
            $trxId = is_string($trxIdVal) ? $trxIdVal : '';

            // Prepare dynamic placeholders
            $vars = [
                'customer' => $customerName,
                'amount'   => $amount,
                'currency' => $currency,
                'trx_id'   => $trxId,
                'url'      => $url,
            ];

            // Render plain text template
            $commService = $container->get(CommunicationService::class);
            if (!$commService instanceof CommunicationService) {
                return;
            }
            $rendered = $commService->renderTemplate($template, $vars);

            // Dispatch through the central sendSms mechanism
            $commService->sendSms($merchantId, $to, $rendered);

        } catch (\Throwable) {
            // Silence exceptions to prevent billing pipeline blockage
        }
    }

    /**
     * Resolves settings mapped dynamically by merchant brand overrides.
     *
     * @param int|null $merchantId Scoped brand context.
     * @return array<string, string> Scoped settings values.
     */
    private function getSettings(?int $merchantId = null): array
    {
        $container = $this->container;
        if ($merchantId !== null && $merchantId > 0 && $container !== null) {
            if ($container->has(SettingsRepository::class)) {
                $repo = $container->get(SettingsRepository::class);
                if ($repo instanceof SettingsRepository) {
                    return $repo->getGroupScoped('plugin.sms-gateway', $merchantId);
                }
            }
        }
        return $this->settings;
    }

    /**
     * Dispatches an outbound SMS message payload.
     *
     * @param string $to Recipient target telephone number.
     * @param string $message Plain text message content payload.
     * @param array<string, mixed> $options Optional vendor-specific routing parameters.
     * @return array{success: bool, message_id?: string, error?: string} Transmission results status block.
     */
    public function send(string $to, string $message, array $options = []): array
    {
        if ($to === '' || $message === '') {
            return ['success' => false, 'error' => 'Missing to/message'];
        }

        $optMid = $options['merchant_id'] ?? null;
        $merchantId = is_numeric($optMid) ? (int) $optMid : null;
        
        $settings = $this->getSettings($merchantId);
        $provider = $settings['provider'] ?? 'custom';

        try {
            return match ($provider) {
                'twilio'  => $this->sendTwilio($to, $message, $settings),
                'vonage'  => $this->sendVonage($to, $message, $settings),
                default   => $this->sendCustom($to, $message, $settings),
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function status(string $messageId): string
    {
        return 'unknown';
    }

    public function balance(): ?float
    {
        return null;
    }

    /**
     * @param string $to
     * @param string $body
     * @param array<string, string> $settings
     * @return array{success: bool, message_id?: string, error?: string}
     */
    private function sendTwilio(string $to, string $body, array $settings): array
    {
        $sid = $settings['twilio_sid'] ?? '';
        $token = $settings['twilio_token'] ?? '';
        $from = $settings['twilio_from'] ?? '';

        if ($sid === '' || $token === '' || $from === '') {
            return ['success' => false, 'error' => 'Twilio credentials missing'];
        }

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
        $sidVal = null;
        if (is_array($data)) {
            $sidVal = $data['sid'] ?? null;
        }
        $res = ['success' => $httpCode >= 200 && $httpCode < 300];
        if (is_string($sidVal)) {
            $res['message_id'] = $sidVal;
        }
        return $res;
    }

    /**
     * @param string $to
     * @param string $body
     * @param array<string, string> $settings
     * @return array{success: bool, message_id?: string, error?: string}
     */
    private function sendVonage(string $to, string $body, array $settings): array
    {
        $key = $settings['vonage_key'] ?? '';
        $secret = $settings['vonage_secret'] ?? '';
        $from = $settings['vonage_from'] ?? 'OwnPay';

        if ($key === '' || $secret === '') {
            return ['success' => false, 'error' => 'Vonage credentials missing'];
        }

        $ch = curl_init('https://rest.nexmo.com/sms/json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => (string) json_encode([
                'api_key'    => $key,
                'api_secret' => $secret,
                'from'       => $from,
                'to'         => $to,
                'text'       => $body
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        $msg = [];
        if (is_array($data) && isset($data['messages']) && is_array($data['messages'])) {
            $msg = $data['messages'][0] ?? [];
            if (!is_array($msg)) {
                $msg = [];
            }
        }
        $statusVal = (isset($msg['status']) && is_scalar($msg['status'])) ? (string) $msg['status'] : '1';
        $msgIdVal = (isset($msg['message-id']) && is_string($msg['message-id'])) ? $msg['message-id'] : null;

        $res = ['success' => $statusVal === '0'];
        if ($msgIdVal !== null) {
            $res['message_id'] = $msgIdVal;
        }
        return $res;
    }

    /**
     * @param string $to
     * @param string $body
     * @param array<string, string> $settings
     * @return array{success: bool, message_id?: string, error?: string}
     */
    private function sendCustom(string $to, string $body, array $settings): array
    {
        $url = $settings['custom_api_url'] ?? '';
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return ['success' => false, 'error' => 'Custom API URL must be HTTPS'];
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|localhost)/i', $host)) {
            return ['success' => false, 'error' => 'Internal URLs blocked (SSRF)'];
        }

        $method = strtoupper($settings['custom_api_method'] ?? 'POST');
        $bodyTemplate = $settings['custom_api_body_template'] ?? '{"to":"{{to}}","message":"{{message}}"}';
        $rendered = str_replace(['{{to}}', '{{message}}'], [$to, $body], $bodyTemplate);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method !== '' ? $method : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => (string) $rendered,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($settings['custom_api_key'] ?? ''),
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => $httpCode >= 200 && $httpCode < 300];
    }
}
