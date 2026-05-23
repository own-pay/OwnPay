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
 * SSLCommerz payment gateway adapter supporting Bangladesh localized and international payment routing.
 */
final class SslCommerzGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    /**
     * Base URL for the SSLCommerz sandbox testing environment.
     */
    private const SANDBOX_URL = 'https://sandbox.sslcommerz.com';

    /**
     * Base URL for the SSLCommerz production gateway environment.
     */
    private const LIVE_URL    = 'https://securepay.sslcommerz.com';

    /**
     * Returns the plugin metadata array.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string} Plugin metadata keys.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'SSLCommerz', 'slug' => 'sslcommerz', 'version' => '1.0.0',
            'description' => 'SSLCommerz payment gateway for Bangladesh',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    /**
     * Returns the unique slug identifying the gateway adapter.
     *
     * @return string Unique slug identifier.
     */
    public function slug(): string { return 'sslcommerz'; }

    /**
     * Returns the descriptive name of the gateway.
     *
     * @return string Descriptive name.
     */
    public function name(): string { return 'SSLCommerz'; }

    /**
     * Returns the version of this gateway adapter.
     *
     * @return string Version string.
     */
    public function version(): string { return '1.0.0'; }

    /**
     * Returns the description of this gateway adapter.
     *
     * @return string Description string.
     */
    public function description(): string { return 'SSLCommerz payment gateway for Bangladesh'; }

    /**
     * Registers plugin event listeners and hooks.
     *
     * @param EventManager $events Hook/filter event manager.
     * @param Container $container DI service container.
     * @return void
     */
    public function register(EventManager $events, Container $container): void {}

    /**
     * Boots the plugin during application startup.
     *
     * @param Container $container DI service container.
     * @return void
     */
    public function boot(Container $container): void {}

    /**
     * Runs cleanup routine on plugin deactivation.
     *
     * @param Container $container DI service container.
     * @return void
     */
    public function deactivate(Container $container): void {}

    /**
     * Runs database and file cleanup on plugin uninstallation.
     *
     * @param Container $container DI service container.
     * @return void
     */
    public function uninstall(Container $container): void {}

    /**
     * Returns the capability set registered by this plugin.
     *
     * @return array<int, Capability> List of capabilities.
     */
    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    /**
     * Defines configuration fields required to set up the gateway in the admin interface.
     *
     * @return array<int, array{name: string, label: string, type: string, required: bool, options?: array<string, string>}> Configuration schema arrays.
     */
    public function fields(): array
    {
        return [
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'store_passwd', 'label' => 'Store Password', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment checkout session with SSLCommerz.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url: string|null} payment response containing the redirect URL or raw HTML form.
     * @throws \RuntimeException If the session initiation request fails.
     */
    public function initiate(array $params, array $credentials): array
    {
        $baseUrl = ($credentials['mode'] ?? 'sandbox') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $postData = [
            'store_id'     => $credentials['store_id'] ?? '',
            'store_passwd' => $credentials['store_passwd'] ?? '',
            'total_amount' => $params['amount'],
            'currency'     => $params['currency'],
            'tran_id'      => $params['trx_id'],
            'success_url'  => $params['redirect_url'],
            'fail_url'     => $params['cancel_url'],
            'cancel_url'   => $params['cancel_url'],
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
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('SSLCommerz error: Invalid response format');
        }

        $status = $data['status'] ?? '';
        $statusStr = is_scalar($status) ? (string) $status : '';
        if ($statusStr !== 'SUCCESS') {
            $failedReason = $data['failedreason'] ?? 'Unknown';
            $failedReasonStr = is_scalar($failedReason) ? (string) $failedReason : 'Unknown';
            throw new \RuntimeException('SSLCommerz error: ' . $failedReasonStr);
        }

        $gatewayPageURL = $data['GatewayPageURL'] ?? null;
        $gatewayPageURLStr = is_scalar($gatewayPageURL) ? (string) $gatewayPageURL : null;

        return ['redirect_url' => $gatewayPageURLStr];
    }

    /**
     * Verifies the authenticity and status of the payment callback transaction from SSLCommerz.
     *
     * @param array<string, mixed> $callbackData Request query/post payload from the gateway callback.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured credentials.
     * @return array{success: bool, gateway_trx_id: string, amount: string|null, status: string} Verification metadata.
     */
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

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'amount'         => null,
                'status'         => 'failed',
            ];
        }
        
        $status = $data['status'] ?? '';
        $statusStr = is_scalar($status) ? (string) $status : '';
        $valid = $statusStr === 'VALID' || $statusStr === 'VALIDATED';

        $bankTranId = $data['bank_tran_id'] ?? '';
        $bankTranIdStr = is_scalar($bankTranId) ? (string) $bankTranId : '';

        $amount = $data['amount'] ?? null;
        $amountStr = is_scalar($amount) ? (string) $amount : null;

        return [
            'success'        => $valid,
            'gateway_trx_id' => $bankTranIdStr,
            'amount'         => $amountStr,
            'status'         => $valid ? 'completed' : 'failed',
        ];
    }

    /**
     * Checks if the gateway adapter supports a given capability.
     *
     * @param string $feature Name of the capability.
     * @return bool True if supported; false otherwise.
     */
    public function supports(string $feature): bool
    {
        return match ($feature) {
            'verification' => true,
            default => false,
        };
    }

    /**
     * Returns an array containing the currencies supported by this gateway.
     *
     * SSLCommerz accepts BDT + major international currencies.
     *
     * @return string[] Array of supported currency codes.
     */
    public function supportedCurrencies(): array
    {
        return ['BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD'];
    }
}

