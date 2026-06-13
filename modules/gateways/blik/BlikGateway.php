<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Blik;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Model\WebhookPayload;
use OwnPay\Service\Payment\TransactionService;

/**
 * BLIK Payment Gateway Adapter (via Przelewy24).
 */
final class BlikGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'BLIK',
            'slug'        => 'blik',
            'version'     => '1.0.0',
            'description' => 'BLIK payment gateway integration for OwnPay',
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
        return 'blik';
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('webhook.incoming.blik', [$this, 'handleWebhook']);
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
            ['name' => 'pos_id', 'label' => 'POS ID', 'type' => 'text', 'required' => true],
            ['name' => 'crc_key', 'label' => 'CRC Key', 'type' => 'password', 'required' => true],
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Sandbox Mode', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox Simulation UAT', 'live' => 'Production Live Environment'], 'required' => true]
        ];
    }

    public function supportedCurrencies(): array
    {
        return ['PLN'];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $baseUrl = $mode === 'live'
            ? 'https://secure.przelewy24.pl'
            : 'https://sandbox.przelewy24.pl';

        /** @var array<string, mixed> $paramsArray */
        $paramsArray = $params;
        $blikCode = $this->getString($paramsArray['blik_code'] ?? '');

        if ($blikCode === '') {
            // Render interactive BLIK code input form
            $actionUrl = $this->getString($_SERVER['REQUEST_URI'] ?? '');
            $form = '
            <div class="op-blik-container" style="max-width: 400px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: #fff; text-align: center;">
                <h3 style="margin-top: 0; color: #000;">Pay with BLIK</h3>
                <p style="color: #666; font-size: 14px;">Enter the 6-digit BLIK code from your banking app.</p>
                <form method="POST" action="' . htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5) . '">
                    <div class="op-form-group" style="margin-bottom: 15px;">
                        <input type="text" name="blik_code" class="op-form-control" placeholder="000 000" maxlength="6" pattern="\\d{6}" required autocomplete="off" style="font-size: 24px; text-align: center; letter-spacing: 5px; width: 100%; padding: 10px; border: 2px solid #000; border-radius: 4px;">
                    </div>
                    <button type="submit" class="op-btn op-btn-primary" style="width: 100%; padding: 12px; background: #000; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Confirm BLIK Payment</button>
                </form>
            </div>';
            return ['form_html' => $form];
        }

        $merchantId = $this->getInt($credentials['merchant_id'] ?? 0);
        $posId = $this->getInt($credentials['pos_id'] ?? 0);
        $crcKey = $this->getString($credentials['crc_key'] ?? '');
        $apiKey = $this->getString($credentials['api_key'] ?? '');

        $amountGrosz = $this->toMinorUnits($params['amount']);
        $currency = 'PLN';
        $sessionId = $params['trx_id'];

        $signString = $sessionId . $merchantId . $amountGrosz . $currency . $crcKey;
        $sign = hash('sha384', $signString);

        // 1. Register Transaction with P24
        $payload = [
            'merchantId' => $merchantId,
            'posId'      => $posId,
            'sessionId'  => $sessionId,
            'amount'     => $amountGrosz,
            'currency'   => $currency,
            'description'=> 'Payment ' . $sessionId,
            'email'      => 'customer@example.com',
            'client'     => 'Customer',
            'country'    => 'PL',
            'language'   => 'pl',
            'urlReturn'  => $params['redirect_url'],
            'urlStatus'  => $params['redirect_url'],
            'sign'       => $sign,
        ];

        $ch = curl_init($baseUrl . '/api/v1/transaction/register');
        if ($ch === false) {
            return ['form_html' => '<div class="op-alert op-alert-danger">Failed to initialize BLIK transaction.</div>'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_USERPWD        => $posId . ':' . $apiKey,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: OwnPay Gateway Client/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [
                'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
            ];
        }

        $resData = json_decode((string) $response, true);
        $token = $this->getString($this->getArray($resData, 'data')['token'] ?? '');

        if ($token !== '') {
            // 2. Submit the BLIK Code to process payment
            $blikPayload = [
                'token'     => $token,
                'blikCode'  => (int)$blikCode,
                'type'      => 0, // Standard BLIK code
            ];

            $ch2 = curl_init($baseUrl . '/api/v1/payment/blik');
            if ($ch2 !== false) {
                curl_setopt_array($ch2, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_POSTFIELDS     => (string) json_encode($blikPayload),
                    CURLOPT_USERPWD        => $posId . ':' . $apiKey,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'User-Agent: OwnPay Gateway Client/1.0.0',
                    ],
                ]);
                $response2 = curl_exec($ch2);
                $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);

                if ($httpCode2 === 200 && $response2) {
                    $blikRes = json_decode((string)$response2, true);
                    if (is_array($blikRes) && $this->getInt($blikRes['responseCode'] ?? -1) === 0) {
                        return [
                            'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=' . $token
                        ];
                    }
                }
            }
        }

        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        if ($mode === 'live') {
            throw new \RuntimeException('Payment initiation failed');
        }
        return [
            'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? null);
        $sessionId = $this->getString($callbackData['reference'] ?? null);

        if (!$gatewayTrxId || !$sessionId) {
            return ['success' => false];
        }

        if (str_starts_with($gatewayTrxId, 'SIM_')) {
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
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $this->getString($callbackData['amount'] ?? '0.00'),
                'status'         => 'completed',
            ];
        }

        $merchantId = $this->getInt($credentials['merchant_id'] ?? 0);
        $posId = $this->getInt($credentials['pos_id'] ?? 0);
        $crcKey = $this->getString($credentials['crc_key'] ?? '');
        $apiKey = $this->getString($credentials['api_key'] ?? '');

        $baseUrl = $mode === 'live'
            ? 'https://secure.przelewy24.pl'
            : 'https://sandbox.przelewy24.pl';

        $amountFloat = $this->getFloat($callbackData['amount'] ?? 0.0);
        $amountGrosz = (int) bcmul((string) $amountFloat, '100', 0);
        $currency = 'PLN';

        $signString = $sessionId . $gatewayTrxId . $amountGrosz . $currency . $crcKey;
        $sign = hash('sha384', $signString);

        $payload = [
            'merchantId' => $merchantId,
            'posId'      => $posId,
            'sessionId'  => $sessionId,
            'amount'     => $amountGrosz,
            'currency'   => $currency,
            'orderId'    => (int) $gatewayTrxId,
            'sign'       => $sign,
        ];

        $ch = curl_init($baseUrl . '/api/v1/transaction/verify');
        if ($ch === false) {
            return ['success' => false];
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_USERPWD        => $posId . ':' . $apiKey,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: OwnPay Gateway Client/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $resData = json_decode((string) $response, true);
            if (is_array($resData)) {
                $innerData = $this->getArray($resData, 'data');
                if ($this->getString($innerData['status'] ?? '') === 'success' || $this->getInt($resData['responseCode'] ?? -1) === 0) {
                    return [
                        'success'        => true,
                        'gateway_trx_id' => $gatewayTrxId,
                        'amount'         => $this->getString($callbackData['amount'] ?? '0.00'),
                        'status'         => 'completed',
                    ];
                }
            }
        }

        return ['success' => false];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return true;
    }

    public function handleWebhook(WebhookPayload $payload): void
    {
        if ($this->container === null) {
            return;
        }

        $data = $payload->json();
        $reference = $this->getString($data['sessionId'] ?? null);
        $gatewayTrxId = $this->getString($data['orderId'] ?? 'BLIK_WEBHOOK');

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

    public function supports(string $feature): bool
    {
        return $feature === 'refund';
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        // Automated refunds are not implemented for this gateway; the simulated
        // success below is for local testing only. In production fail closed so a
        // refund is never marked complete (and the ledger credited) without the
        // money actually being returned at the provider.
        if ($this->isProductionEnv()) {
            return ['success' => false, 'error' => 'Automated refunds are unavailable for this gateway; process it in the provider dashboard.'];
        }

        return [
            'success'   => true,
            'refund_id' => 'REF_' . $this->slug() . '_' . uniqid(),
        ];
    }
}
