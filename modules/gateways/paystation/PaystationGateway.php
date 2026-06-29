<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Paystation;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PayStation Gateway - PluginInterface + GatewayAdapterInterface.
 */
final class PaystationGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name'        => 'PayStation',
            'slug'        => 'paystation',
            'version'     => '1.0.0',
            'description' => 'Accept PayStation payments directly from customers.',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string { return 'paystation'; }
    public function name(): string { return 'PayStation'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Accept PayStation payments directly from customers.'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            [
                'name'     => 'merchant_id',
                'label'    => 'Merchant ID',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'merchant_password',
                'label'     => 'Merchant Password',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'checkout_items',
                'label'    => 'Checkout Items',
                'type'     => 'text',
                'required' => false
            ],
            [
                'name'     => 'pay_with_charge',
                'label'    => 'Fee Pay',
                'type'     => 'select',
                'options'  => ['customer' => 'Customer', 'merchant' => 'Merchant'],
                'required' => true
            ],
            [
                'name'     => 'merchant_mode',
                'label'    => 'Mode',
                'type'     => 'select',
                'options'  => ['sandbox' => 'sandbox', 'live' => 'live'],
                'required' => true
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $credentials['merchant_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? 'https://api.paystation.com.bd' : 'https://sandbox.paystation.com.bd';
        $url = $baseUrl . '/initiate-payment';

        $merchantId = $credentials['merchant_id'] ?? '';
        $merchantPassword = $credentials['merchant_password'] ?? '';
        $payWithCharge = ($credentials['pay_with_charge'] ?? 'customer') === 'merchant' ? '1' : '0';
        $checkoutItems = $credentials['checkout_items'] ?? 'Payment';

        $trxId = $params['trx_id'];
        $amount = number_format((float) $params['amount'], 2, '.', '');
        $redirectUrl = $params['redirect_url'];

        $postFields = [
            'invoice_number'  => $trxId,
            'currency'        => 'BDT',
            'payment_amount'  => $amount,
            'reference'       => $trxId,
            'cust_name'       => $params['metadata']['customer_name'] ?? 'Customer',
            'cust_phone'      => $params['metadata']['customer_phone'] ?? '01700000000',
            'cust_email'      => $params['metadata']['customer_email'] ?? 'customer@example.com',
            'cust_address'    => 'Bangladesh',
            'pay_with_charge' => $payWithCharge,
            'callback_url'    => $redirectUrl,
            'checkout_items'  => $checkoutItems,
            'merchantId'      => $merchantId,
            'password'        => $merchantPassword
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS     => $postFields,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode !== 200 && $httpCode !== 201) || !$response) {
            throw new \RuntimeException('PayStation API Error: HTTP ' . $httpCode);
        }

        $result = json_decode((string) $response, true);
        if (!is_array($result) || empty($result['payment_url']) || !is_string($result['payment_url'])) {
            $errMsg = (is_array($result) && isset($result['message']) && is_scalar($result['message'])) ? (string) $result['message'] : 'Missing payment URL';
            throw new \RuntimeException('PayStation Initiation Error: ' . $errMsg);
        }

        return [
            'redirect_url' => $result['payment_url'],
            'session_id'   => $trxId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $statusRaw = $callbackData['status'] ?? '';
        $status = is_scalar($statusRaw) ? (string) $statusRaw : '';
        
        $invoiceNumberRaw = $callbackData['invoice_number'] ?? '';
        $invoiceNumber = is_scalar($invoiceNumberRaw) ? (string) $invoiceNumberRaw : '';

        if (empty($invoiceNumber)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'pending',
                'trx_id'         => '',
            ];
        }

        if ($status !== 'Successful') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'trx_id'         => $invoiceNumber,
            ];
        }

        $mode = $credentials['merchant_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? 'https://api.paystation.com.bd' : 'https://sandbox.paystation.com.bd';
        $url = $baseUrl . '/transaction-status';

        $merchantIdRaw = $credentials['merchant_id'] ?? '';
        $merchantId = is_scalar($merchantIdRaw) ? (string) $merchantIdRaw : '';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'merchantId: ' . $merchantId
            ],
            CURLOPT_POSTFIELDS     => [
                'invoice_number' => $invoiceNumber
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'trx_id'         => $invoiceNumber,
            ];
        }

        $result = json_decode((string) $response, true);
        if (is_array($result) && ($result['status_code'] ?? '') === '200' && ($result['status'] ?? '') === 'success') {
            $data = $result['data'] ?? [];
            if (is_array($data)) {
                $trxStatus = isset($data['trx_status']) && is_scalar($data['trx_status']) ? (string) $data['trx_status'] : '';
                $isPaid = in_array(strtolower($trxStatus), ['successful', 'success'], true);

                if ($isPaid) {
                    $gatewayTrxId = isset($data['trx_id']) && is_scalar($data['trx_id']) ? (string) $data['trx_id'] : $invoiceNumber;
                    $amount = $data['payment_amount'] ?? null;

                    $res = [
                        'success'        => true,
                        'gateway_trx_id' => $gatewayTrxId,
                        'status'         => 'completed',
                        'trx_id'         => $invoiceNumber,
                    ];
                    if ($amount !== null && is_scalar($amount)) {
                        $res['amount'] = (string) $amount;
                    }
                    return $res;
                }
            }
        }

        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'failed',
            'trx_id'         => $invoiceNumber,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}
