<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\ManualGatewayRepository;

/**
 * Gateway renderer service Ã¢€— provides gateway data for checkout UI rendering.
 *
 * Per PCI-DSS: never expose raw credentials to frontend.
 */
final class GatewayRendererService
{
    private GatewayConfigRepository $apiConfigs;
    private ManualGatewayRepository $manualGateways;

    public function __construct(
        GatewayConfigRepository $apiConfigs,
        ManualGatewayRepository $manualGateways
    ) {
        $this->apiConfigs = $apiConfigs;
        $this->manualGateways = $manualGateways;
    }

    /**
     * Get all available gateways for checkout rendering.
     *
     * @return array{api: array, manual: array}
     */
    public function getForCheckout(int $merchantId): array
    {
        // API gateways (credentials stripped)
        $apiGateways = $this->apiConfigs->forTenant($merchantId)->listActiveWithGateway();
        $apiForFrontend = [];
        foreach ($apiGateways as $gw) {
            $apiForFrontend[] = [
                'slug'         => $gw['slug'],
                'name'         => $gw['name'],
                'logo'         => $gw['logo'] ?? null,
                'type'         => 'api',
                'currencies'   => json_decode($gw['supported_currencies'] ?? '[]', true),
                'min_amount'   => $gw['min_amount'] ?? null,
                'max_amount'   => $gw['max_amount'] ?? null,
            ];
        }

        // Manual gateways
        $manualGateways = $this->manualGateways->forTenant($merchantId)->listActive();
        $manualForFrontend = [];
        foreach ($manualGateways as $mg) {
            $manualForFrontend[] = [
                'slug'           => $mg['slug'],
                'name'           => $mg['name'],
                'logo_path'      => $mg['logo_path'] ?? null,
                'qr_code_path'   => $mg['qr_code_path'] ?? null,
                'type'           => 'manual',
                'instructions'   => $mg['instructions'] ?? '',
                'input_fields'   => json_decode($mg['input_fields'] ?? '[]', true),
                'sms_verification' => (bool) ($mg['sms_verification'] ?? false),
                'min_amount'     => $mg['min_amount'] ?? null,
                'max_amount'     => $mg['max_amount'] ?? null,
            ];
        }

        return [
            'api'    => $apiForFrontend,
            'manual' => $manualForFrontend,
        ];
    }

    /**
     * Get single gateway render data.
     */
    public function getGateway(int $merchantId, string $slug): ?array
    {
        $all = $this->getForCheckout($merchantId);
        foreach (array_merge($all['api'], $all['manual']) as $gw) {
            if ($gw['slug'] === $slug) {
                return $gw;
            }
        }
        return null;
    }
}
