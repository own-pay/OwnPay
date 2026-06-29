<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BkashApi;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * bKash API gateway adapter implementing the tokenized checkout flow.
 */
final class BkashApiGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    /**
     * Base URL for the bKash sandbox API endpoint.
     */
    private const SANDBOX_URL = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

    /**
     * Base URL for the bKash production API endpoint.
     */
    private const LIVE_URL    = 'https://tokenized.pay.bka.sh/v1.2.0-beta';

    /**
     * Returns the plugin metadata array.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string} Plugin metadata keys.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'bKash API', 'slug' => 'bkash-api', 'version' => '1.0.0',
            'description' => 'bKash tokenized checkout API integration',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    /**
     * Returns the unique slug identifying the gateway adapter.
     *
     * @return string Unique slug identifier.
     */
    public function slug(): string { return 'bkash-api'; }

    /**
     * Returns the descriptive name of the gateway.
     *
     * @return string Descriptive name.
     */
    public function name(): string { return 'bKash API'; }

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
    public function description(): string { return 'bKash tokenized checkout API integration'; }

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
            ['name' => 'app_key', 'label' => 'App Key', 'type' => 'text', 'required' => true],
            ['name' => 'app_secret', 'label' => 'App Secret', 'type' => 'password', 'required' => true],
            ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment session with the bKash Tokenized API.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url: string, session_id: string|null} payment response containing the redirect URL or raw HTML form.
     * @throws \RuntimeException If required credentials are missing or API response fails.
     */
    public function initiate(array $params, array $credentials): array
    {
        if (empty($credentials['username']) || empty($credentials['password']) || empty($credentials['app_key']) || empty($credentials['app_secret'])) {
            throw new \RuntimeException('bKash error: Missing credentials. Please configure bKash in active gateways settings.');
        }

        $mode = $credentials['mode'] ?? 'sandbox';
        if ($mode === '1' || $mode === 1) {
            $mode = 'live';
        } elseif ($mode === '0' || $mode === 0) {
            $mode = 'sandbox';
        }
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $token = $this->getToken($baseUrl, $credentials);

        $appKeyRaw = $credentials['app_key'];
        $appKey = is_scalar($appKeyRaw) ? (string) $appKeyRaw : '';

        $ch = curl_init($baseUrl . '/tokenized/checkout/create');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'X-APP-Key: ' . $appKey,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode([
                'mode'                => '0011',
                'payerReference'      => $params['trx_id'],
                'callbackURL'         => $params['redirect_url'],
                'amount'              => $params['amount'],
                'currency'            => 'BDT',
                'intent'              => 'sale',
                'merchantInvoiceNumber' => $params['trx_id'],
            ]),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('bKash API connection error: ' . ($err ?: 'Unknown'));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('bKash error: Invalid API response payload.');
        }

        $bkashURL = isset($data['bkashURL']) && is_scalar($data['bkashURL']) ? (string) $data['bkashURL'] : '';

        if ($bkashURL === '') {
            $statusCode = isset($data['statusCode']) && is_scalar($data['statusCode']) ? (string) $data['statusCode'] : '';
            $statusMsg = isset($data['statusMessage']) && is_scalar($data['statusMessage']) ? (string) $data['statusMessage'] : 'Unknown';
            $errorDetail = $statusCode !== '' ? "[{$statusCode}] {$statusMsg}" : $statusMsg;
            throw new \RuntimeException('bKash error: ' . $errorDetail);
        }

        $paymentID = isset($data['paymentID']) && is_scalar($data['paymentID']) ? (string) $data['paymentID'] : null;

        return [
            'redirect_url' => $bkashURL,
            'session_id'   => $paymentID,
        ];
    }

    /**
     * Executes the payment verification on bKash API.
     *
     * @param array<string, mixed> $callbackData Request query/post payload from the gateway callback.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured credentials.
     * @return array{success: bool, gateway_trx_id: string, amount: string|null, status: string, error?: string} Verification metadata.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $credentials['mode'] ?? 'sandbox';
        if ($mode === '1' || $mode === 1) {
            $mode = 'live';
        } elseif ($mode === '0' || $mode === 0) {
            $mode = 'sandbox';
        }
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $token = $this->getToken($baseUrl, $credentials);
        $paymentIdRaw = $callbackData['paymentID'] ?? '';
        $paymentId = is_scalar($paymentIdRaw) ? (string) $paymentIdRaw : '';

        $appKeyRaw = $credentials['app_key'] ?? '';
        $appKey = is_scalar($appKeyRaw) ? (string) $appKeyRaw : '';

        $ch = curl_init($baseUrl . '/tokenized/checkout/execute');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'X-APP-Key: ' . $appKey,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode(['paymentID' => $paymentId]),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'amount'         => null,
                'status'         => 'failed',
                'error'          => 'bKash API connection error: ' . ($err ?: 'Unknown'),
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'amount'         => null,
                'status'         => 'failed',
                'error'          => 'bKash API verification failed: Invalid response',
            ];
        }

        $statusCode = isset($data['statusCode']) && is_scalar($data['statusCode']) ? (string) $data['statusCode'] : '';
        $trxStatus = isset($data['transactionStatus']) && is_scalar($data['transactionStatus']) ? (string) $data['transactionStatus'] : '';
        $success = $statusCode === '0000' && $trxStatus === 'Completed';

        $trxID = isset($data['trxID']) && is_scalar($data['trxID']) ? (string) $data['trxID'] : '';
        $amountVal = isset($data['amount']) && is_scalar($data['amount']) ? (string) $data['amount'] : null;

        return [
            'success'        => $success,
            'gateway_trx_id' => $trxID,
            'amount'         => $amountVal,
            'status'         => $success ? 'completed' : 'failed',
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
     * bKash operates in BDT.
     *
     * @return string[] Array of supported currency codes.
     */
    public function supportedCurrencies(): array
    {
        return ['BDT'];
    }

    /**
     * Cache token per base URL with TTL.
     *
     * bKash tokens are valid for ~60min - use 55min TTL with safety margin.
     * Static property persists in PHP-FPM workers, so TTL is essential.
     *
     * @var array<string, array{token: string, expires_at: int}>
     */
    private static array $tokenCache = [];

    /**
     * Retrieves or grants a token for the bKash API, using the in-memory static cache.
     *
     * @param string $baseUrl Target environment base API URL.
     * @param array<string, mixed> $credentials Decrypted merchant credentials.
     * @return string Granted API token.
     * @throws \RuntimeException If the token request fails.
     */
    private function getToken(string $baseUrl, array $credentials): string
    {
        $appKeyRaw = $credentials['app_key'] ?? '';
        $appKey = is_scalar($appKeyRaw) ? (string) $appKeyRaw : '';

        // Return cached token if available AND not expired
        $cacheKey = $baseUrl . ':' . $appKey;
        if (isset(self::$tokenCache[$cacheKey])) {
            $cached = self::$tokenCache[$cacheKey];
            if ($cached['expires_at'] > time()) {
                return $cached['token'];
            }
            unset(self::$tokenCache[$cacheKey]);
        }

        $userRaw = $credentials['username'] ?? '';
        $user = is_scalar($userRaw) ? (string) $userRaw : '';
        $passRaw = $credentials['password'] ?? '';
        $pass = is_scalar($passRaw) ? (string) $passRaw : '';
        $appSecretRaw = $credentials['app_secret'] ?? '';
        $appSecret = is_scalar($appSecretRaw) ? (string) $appSecretRaw : '';

        $ch = curl_init($baseUrl . '/tokenized/checkout/token/grant');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'username: ' . $user,
                'password: ' . $pass,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode([
                'app_key'    => $appKey,
                'app_secret' => $appSecret,
            ]),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('bKash Token Grant API connection error: ' . ($err ?: 'Unknown'));
        }

        $data = json_decode((string) $response, true);
        $token = '';
        if (is_array($data) && isset($data['id_token']) && is_scalar($data['id_token'])) {
            $token = (string) $data['id_token'];
        }

        if ($token !== '') {
            self::$tokenCache[$cacheKey] = [
                'token'      => $token,
                'expires_at' => time() + 3300, // 55 minutes
            ];
        }

        return $token;
    }
}

