<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\SslCommerz;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * SSLCommerz gateway — Bangladesh payment gateway.
 */
final class SslCommerzGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://sandbox.sslcommerz.com';
    private const LIVE_URL    = 'https://securepay.sslcommerz.com';

    public static function metadata(): array
    {
        return [
            'name' => 'SSLCommerz', 'slug' => 'sslcommerz', 'version' => '1.0.0',
            'description' => 'SSLCommerz payment gateway for Bangladesh',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'sslcommerz'; }
    public function name(): string { return 'SSLCommerz'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'SSLCommerz payment gateway for Bangladesh'; }

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
            ['name' => 'store_passwd', 'label' => 'Store Password', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox', 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $baseUrl = ($credentials['mode'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $postData = [
            'store_id'     => $credentials['store_id'] ?? '',
            'store_passwd' => $credentials['store_passwd'] ?? '',
            'total_amount' => $params['amount'],
            'currency'     => $params['currency'],
            'tran_id'      => $params['trx_id'],
            'success_url'  => $params['redirect_url'] ?? '',
            'fail_url'     => $params['cancel_url'] ?? '',
            'cancel_url'   => $params['cancel_url'] ?? '',
            'cus_name'     => 'Customer',
            'cus_email'    => 'customer@example.com',
            'cus_phone'    => '01700000000',
            'product_name' => 'Payment',
            'product_category' => 'payment',
            'product_profile' => 'general',
            'shipping_method' => 'NO',
        ];

        $ch = curl_init($baseUrl . '/gwprocess/v4/api.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        if (($data['status'] ?? '') !== 'SUCCESS') {
            throw new \RuntimeException('SSLCommerz error: ' . ($data['failedreason'] ?? 'Unknown'));
        }

        return ['redirect_url' => $data['GatewayPageURL'] ?? null];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $baseUrl = ($credentials['mode'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $valId = $callbackData['val_id'] ?? '';

        $url = $baseUrl . '/validator/api/validationserverAPI.php?' . http_build_query([
            'val_id'       => $valId,
            'store_id'     => $credentials['store_id'] ?? '',
            'store_passwd' => $credentials['store_passwd'] ?? '',
            'format'       => 'json',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $valid = ($data['status'] ?? '') === 'VALID' || ($data['status'] ?? '') === 'VALIDATED';

        return [
            'success'        => $valid,
            'gateway_trx_id' => $data['bank_tran_id'] ?? '',
            'amount'         => $data['amount'] ?? null,
            'status'         => $valid ? 'completed' : 'failed',
        ];
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'verification' => true,
            default => false,
        };
    }

    /** SSLCommerz accepts BDT + major international currencies. */
    public function supportedCurrencies(): array
    {
        return ['BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD'];
    }
}
