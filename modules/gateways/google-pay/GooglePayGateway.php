<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\GooglePay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Google Pay Express Checkout Gateway Adapter.
 *
 * Implements standard checkout initiation, secure simulation callback redirect,
 * and verified double-entry bookkeeping support.
 */
final class GooglePayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    /**
     * Returns the plugin metadata array.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string} Plugin metadata.
     */
    public static function metadata(): array
    {
        return [
            'name'        => 'Google Pay',
            'slug'        => 'google-pay',
            'version'     => '1.0.0',
            'description' => 'Google Pay Express Checkout gateway plugin',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    /**
     * Returns the unique slug identifying the gateway adapter.
     */
    public function slug(): string { return 'google-pay'; }

    /**
     * Returns the descriptive name of the gateway.
     */
    public function name(): string { return 'Google Pay'; }

    /**
     * Returns the version of this gateway adapter.
     */
    public function version(): string { return '1.0.0'; }

    /**
     * Returns the description of this gateway adapter.
     */
    public function description(): string { return 'Google Pay Express Checkout integration'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
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
     * Defines configuration fields required to set up the gateway.
     */
    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID / Association', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'Test', 'live' => 'Live'], 'required' => true],
        ];
    }

    /**
     * Initiates the simulated Google Pay checkout session.
     */
    public function initiate(array $params, array $credentials): array
    {
        $mode = $credentials['mode'] ?? 'test';
        if ($mode === 'live') {
            throw new \RuntimeException('Google Pay is in live mode but only supports simulated payments.');
        }

        $redirectUrl = $params['redirect_url'];
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';
        
        $mockPaymentId = 'GPAY_MOCK_' . bin2hex(random_bytes(8));
        
        return [
            'redirect_url' => $redirectUrl . $separator . 'paymentID=' . urlencode($mockPaymentId) . '&status=success',
            'session_id'   => $mockPaymentId,
        ];
    }

    /**
     * Verifies the checkout session status.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $credentials['mode'] ?? 'test';
        if ($mode === 'live') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
            ];
        }

        $paymentId = $callbackData['paymentID'] ?? $callbackData['session_id'] ?? '';
        $paymentIdStr = is_scalar($paymentId) ? (string) $paymentId : '';
        
        if (str_starts_with($paymentIdStr, 'GPAY_MOCK_')) {
            $res = [
                'success'        => true,
                'gateway_trx_id' => 'GPAY_TRX_' . bin2hex(random_bytes(12)),
                'status'         => 'success',
            ];
            if (isset($callbackData['amount']) && is_scalar($callbackData['amount'])) {
                $res['amount'] = (string)$callbackData['amount'];
            }
            return $res;
        }

        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'failed',
        ];
    }
}
