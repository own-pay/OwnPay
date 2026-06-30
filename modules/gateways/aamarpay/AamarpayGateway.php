<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Aamarpay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Aamarpay payment gateway - PluginInterface + GatewayAdapterInterface.
 */
final class AamarpayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://sandbox.aamarpay.com';
    private const LIVE_URL    = 'https://secure.aamarpay.com';

    public static function metadata(): array
    {
        return [
            'name' => 'Aamarpay', 'slug' => 'aamarpay', 'version' => '1.0.0',
            'description' => 'Aamarpay payment gateway integration',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'aamarpay'; }
    public function name(): string { return 'Aamarpay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Aamarpay payment gateway integration'; }

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
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'signature_key', 'label' => 'Signature Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $storeId = $credentials['store_id'] ?? '';
        $signatureKey = $credentials['signature_key'] ?? '';
        $mode = $credentials['mode'] ?? 'sandbox';

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $separator = (strpos($params['redirect_url'], '?') !== false) ? '&' : '?';
        $trxId = $params['trx_id'];

        // Extract or default customer details from metadata
        $email = $params['metadata']['customer_email'] ?? 'customer@example.com';
        $phone = $params['metadata']['customer_phone'] ?? '01700000000';
        $name  = $params['metadata']['customer_name'] ?? 'Customer';

        $payload = [
            'store_id'      => $storeId,
            'tran_id'       => $trxId,
            'success_url'   => $params['redirect_url'] . $separator . 'session=' . urlencode($trxId),
            'fail_url'      => $params['cancel_url'],
            'cancel_url'    => $params['cancel_url'],
            'amount'        => $params['amount'],
            'currency'      => $params['currency'],
            'signature_key' => $signatureKey,
            'desc'          => 'Payment ' . $trxId,
            'cus_name'      => $name,
            'cus_email'     => $email,
            'cus_phone'     => $phone,
            'cus_add1'      => 'Dhaka',
            'cus_add2'      => 'Dhaka',
            'cus_city'      => 'Dhaka',
            'cus_state'     => 'Dhaka',
            'cus_postcode'  => '1200',
            'cus_country'   => 'Bangladesh',
            'type'          => 'json',
            'opt_a'         => $trxId,
        ];

        $ch = curl_init($baseUrl . '/jsonpost.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('Aamarpay API connection error: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['payment_url']) || !is_string($data['payment_url'])) {
            throw new \RuntimeException('Aamarpay initiation failed: ' . $response);
        }

        return [
            'redirect_url' => $data['payment_url'],
            'session_id'   => $trxId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $rawTrxId = $callbackData['session'] ?? $callbackData['pay_status'] ?? '';
        if ($rawTrxId === '' && isset($callbackData['opt_a'])) {
            $rawTrxId = $callbackData['opt_a'];
        }
        $trxId = is_scalar($rawTrxId) ? (string) $rawTrxId : '';

        if ($trxId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $storeId = $credentials['store_id'] ?? '';
        $signatureKey = $credentials['signature_key'] ?? '';
        $mode = $credentials['mode'] ?? 'sandbox';

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $url = $baseUrl . '/api/v1/trxcheck/request.php?' . http_build_query([
            'request_id'    => $trxId,
            'store_id'      => $storeId,
            'signature_key' => $signatureKey,
            'type'          => 'json'
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $payStatus = isset($data['pay_status']) && is_scalar($data['pay_status']) ? (string) $data['pay_status'] : '';
        $statusCode = isset($data['status_code']) && is_scalar($data['status_code']) ? (string) $data['status_code'] : '';
        $paid = $payStatus === 'Successful' && $statusCode === '2';

        $bankTrxId = isset($data['bank_trxid']) && is_scalar($data['bank_trxid']) ? (string) $data['bank_trxid'] : '';
        $amountVal = isset($data['amount']) && is_scalar($data['amount']) ? (string) $data['amount'] : '';
        $pgTxnId = isset($data['pg_txnid']) && is_scalar($data['pg_txnid']) ? (string) $data['pg_txnid'] : (string) $trxId;

        return [
            'success'        => $paid,
            'gateway_trx_id' => $bankTrxId,
            'amount'         => $amountVal,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $pgTxnId,
        ];
    }
}
