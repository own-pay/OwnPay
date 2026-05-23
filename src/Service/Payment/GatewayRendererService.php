<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\ManualGatewayRepository;

/**
 * Prepares and renders gateway profiles for the checkout user interface.
 *
 * Scopes active gateways by merchant brand, formatting lists of both automated (API-based)
 * and manual payment methods. Conforms strictly to PCI-DSS compliance rules by omitting
 * raw merchant credentials, keys, or endpoints from checkout UI data vectors.
 */
final class GatewayRendererService
{
    /**
     * @var GatewayConfigRepository The repository for gateway API settings.
     */
    private GatewayConfigRepository $apiConfigs;

    /**
     * @var ManualGatewayRepository The repository for merchant manual gateways.
     */
    private ManualGatewayRepository $manualGateways;

    /**
     * GatewayRendererService constructor.
     *
     * @param GatewayConfigRepository $apiConfigs Config storage for integrated API gateways.
     * @param ManualGatewayRepository $manualGateways Config storage for custom manual gateways.
     */
    public function __construct(
        GatewayConfigRepository $apiConfigs,
        ManualGatewayRepository $manualGateways
    ) {
        $this->apiConfigs = $apiConfigs;
        $this->manualGateways = $manualGateways;
    }

    /**
     * Retrieves and structures active payment gateways for checkout rendering.
     *
     * Queries automated API gateways and manual gateways for the brand, sanitizes them,
     * formats limit boundaries, and strips all private credentials before sending to the client.
     *
     * @param int $merchantId The unique ID of the merchant/brand.
     * @return array{api: array<int, array<string, mixed>>, manual: array<int, array<string, mixed>>} Structured arrays of frontend-safe gateways.
     */
    public function getForCheckout(int $merchantId): array
    {
        // API gateways (credentials stripped)
        $apiGateways = $this->apiConfigs->forTenant($merchantId)->listActiveWithGateway();
        $apiForFrontend = [];
        foreach ($apiGateways as $gw) {
            $supportedCurrencies = $gw['supported_currencies'] ?? '[]';
            $apiForFrontend[] = [
                'slug'         => $gw['slug'],
                'name'         => $gw['name'],
                'logo'         => $gw['logo'] ?? null,
                'type'         => 'api',
                'currencies'   => json_decode(is_string($supportedCurrencies) ? $supportedCurrencies : '[]', true),
                'min_amount'   => $gw['min_amount'] ?? null,
                'max_amount'   => $gw['max_amount'] ?? null,
            ];
        }

        // Manual gateways
        $manualGateways = $this->manualGateways->forTenant($merchantId)->listActive();
        $manualForFrontend = [];
        foreach ($manualGateways as $mg) {
            $inputFields = $mg['input_fields'] ?? '[]';
            $manualForFrontend[] = [
                'slug'           => $mg['slug'],
                'name'           => $mg['name'],
                'logo_path'      => $mg['logo_path'] ?? null,
                'qr_code_path'   => $mg['qr_code_path'] ?? null,
                'type'           => 'manual',
                'instructions'   => $mg['instructions'] ?? '',
                'input_fields'   => json_decode(is_string($inputFields) ? $inputFields : '[]', true),
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
     * Retrieves the checkout-safe details of a single gateway identified by its slug.
     *
     * @param int $merchantId The unique ID of the merchant/brand.
     * @param string $slug The unique identifier slug of the target gateway.
     * @return array<string, mixed>|null The formatted gateway details, or null if not active or found.
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
