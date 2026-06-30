<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Payfast;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Model\WebhookPayload;
use OwnPay\Service\Payment\TransactionService;

/**
 * Payfast Payment Gateway Adapter.
 */
final class PayfastGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Payfast',
            'slug'        => 'payfast',
            'version'     => '1.0.0',
            'description' => 'Payfast payment gateway integration for South Africa',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function capabilities(): array
    {
        return [
            Capability::GATEWAY,
            Capability::HTTP_OUTBOUND,
            Capability::HOOKS,
        ];
    }

    public function slug(): string
    {
        return 'payfast';
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('webhook.incoming.payfast', [$this, 'handleWebhook']);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void
    {
    }

    public function uninstall(Container $container): void
    {
    }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'merchant_key', 'label' => 'Merchant Key', 'type' => 'text', 'required' => true],
            ['name' => 'passphrase', 'label' => 'Secure Passphrase', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Sandbox Mode', 'type' => 'select', 'options' => [
                'sandbox' => 'Sandbox Simulation UAT',
                'live'    => 'Production Live Environment',
            ], 'required' => true],
        ];
    }

    public function supportedCurrencies(): array
    {
        return ['ZAR'];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function getEndpoint(array $credentials): string
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        return $mode === 'live'
            ? 'https://www.payfast.co.za/eng/process'
            : 'https://sandbox.payfast.co.za/eng/process';
    }

    public function initiate(array $params, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $merchantKey = $this->getString($credentials['merchant_key'] ?? '');
        $passphrase = $this->getString($credentials['passphrase'] ?? '');
        $endpoint = $this->getEndpoint($credentials);

        $amount = number_format((float) $params['amount'], 2, '.', '');

        $data = [
            'merchant_id'  => $merchantId,
            'merchant_key' => $merchantKey,
            'return_url'   => $params['redirect_url'],
            'cancel_url'   => $params['cancel_url'],
            'notify_url'   => $params['redirect_url'], // Using same redirect for simplicity / callback
            'm_payment_id' => $params['trx_id'],
            'amount'       => $amount,
            'item_name'    => 'Payment ' . $params['trx_id'],
        ];

        // Generate Signature
        $sigString = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $sigString .= $key . '=' . urlencode(trim((string)$val)) . '&';
            }
        }
        $sigString = rtrim($sigString, '&');
        if ($passphrase !== '') {
            $sigString .= '&passphrase=' . urlencode($passphrase);
        }
        $signature = md5($sigString);
        $data['signature'] = $signature;

        // Construct HTML form redirect to Payfast
        $html = '<form action="' . htmlspecialchars($endpoint) . '" method="POST" id="payfast_checkout_form">';
        foreach ($data as $key => $val) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$val) . '"/>';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("payfast_checkout_form").submit();</script>';

        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        if ($mode === 'sandbox' && !$this->isProductionEnv()) {
            // Emulate fallback visual window for simulated checkout offline
            return [
                'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
            ];
        }

        return [
            'form_html' => $html,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $mPaymentId = $this->getString($callbackData['m_payment_id'] ?? $callbackData['reference'] ?? $callbackData['trx_id'] ?? '');
        $pfPaymentId = $this->getString($callbackData['pf_payment_id'] ?? $callbackData['gateway_trx_id'] ?? '');

        if ($pfPaymentId === '' || str_starts_with($pfPaymentId, 'SIM_')) {
            $mode = $this->getString($credentials['mode'] ?? 'sandbox');
            if ($mode === 'live') {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'status'         => 'failed',
                ];
            }
            return [
                'success'        => true,
                'gateway_trx_id' => $this->getString($callbackData['gateway_trx_id'] ?? 'SIM_TXN_' . uniqid()),
                'amount'         => $this->getString($callbackData['amount'] ?? '0.00'),
                'status'         => 'completed',
            ];
        }

        // Payfast ITN Verification backchannel
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $host = $mode === 'live' ? 'www.payfast.co.za' : 'sandbox.payfast.co.za';
        $endpoint = 'https://' . $host . '/eng/query/validate';

        // Prepare POST variables back to Payfast
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['success' => false];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query($callbackData),
            CURLOPT_HTTPHEADER     => [
                'User-Agent: OwnPay Gateway Client/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $success = $response === 'VALID' && ($callbackData['payment_status'] ?? '') === 'COMPLETE';

        return [
            'success'        => $success,
            'gateway_trx_id' => $pfPaymentId,
            'amount'         => $this->getString($callbackData['amount_gross'] ?? null),
            'status'         => $success ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // Payfast posts variables directly
        parse_str($rawBody, $data);
        $sigVal = $data['signature'] ?? null;
        if (!is_scalar($sigVal) || (string)$sigVal === '') {
            return false;
        }

        $signature = (string) $sigVal;
        unset($data['signature']);

        $passphrase = $this->getString($credentials['passphrase'] ?? '');

        $sigString = '';
        foreach ($data as $key => $val) {
            if (is_scalar($val) && (string)$val !== '') {
                $sigString .= $key . '=' . urlencode(trim((string)$val)) . '&';
            }
        }
        $sigString = rtrim($sigString, '&');
        if ($passphrase !== '') {
            $sigString .= '&passphrase=' . urlencode($passphrase);
        }
        $expectedSignature = md5($sigString);

        return hash_equals($expectedSignature, $signature);
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        if ($this->container === null) {
            return;
        }

        $data = $payload->json();
        $reference = $this->getString($data['m_payment_id'] ?? null);
        $gatewayTrxId = $this->getString($data['pf_payment_id'] ?? 'PAYFAST_WEBHOOK');

        if ($reference !== '') {
            /** @var \OwnPay\Repository\TransactionRepository $trxRepo */
            $trxRepo = $this->container->get(\OwnPay\Repository\TransactionRepository::class);
            $scopedRepo = $trxRepo->forTenant($payload->merchantId);
            $trx = $scopedRepo->findByTrxId($reference);

            if ($trx && ($trx['status'] ?? '') === 'pending') {
                $trxId = $this->getInt($trx['id'] ?? 0);
                if ($trxId > 0) {
                    $scopedRepo->updateScoped($trxId, ['gateway_trx_id' => $gatewayTrxId]);
                    /** @var \OwnPay\Service\Payment\TransactionService $trxService */
                    $trxService = $this->container->get(\OwnPay\Service\Payment\TransactionService::class);
                    $trxService->complete($trxId, $payload->merchantId);
                }
            }
        }
    }
}
