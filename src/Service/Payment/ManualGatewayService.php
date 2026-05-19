<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\ManualGatewayRepository;

/**
 * Manual gateway service — handles manual payment gateways (bKash, bank transfer, etc).
 *
 * Fires: gateway.manual.render, gateway.manual.verify
 */
final class ManualGatewayService
{
    private ManualGatewayRepository $gateways;
    private EventManager $events;

    public function __construct(ManualGatewayRepository $gateways, EventManager $events)
    {
        $this->gateways = $gateways;
        $this->events = $events;
    }

    /**
     * Get gateway form data for checkout rendering.
     */
    public function getFormData(int $merchantId, string $slug): ?array
    {
        $gateway = $this->gateways->forTenant($merchantId)->findBySlug($slug);
        if ($gateway === null || $gateway['status'] !== 'active') {
            return null;
        }

        $formData = [
            'name'         => $gateway['name'],
            'slug'         => $gateway['slug'],
            'logo_path'    => $gateway['logo_path'],
            'qr_code_path' => $gateway['qr_code_path'],
            'instructions' => $gateway['instructions'],
            'input_fields' => json_decode($gateway['input_fields'] ?? '[]', true),
            'colors'       => json_decode($gateway['colors'] ?? '{}', true),
            'min_amount'   => $gateway['min_amount'],
            'max_amount'   => $gateway['max_amount'],
            'sms_verification' => (bool) $gateway['sms_verification'],
        ];

        // Plugin filter for custom form rendering
        return $this->events->applyFilter('gateway.manual.render', $formData, $gateway);
    }

    /**
     * Verify manual payment submission.
     */
    public function verify(int $merchantId, string $slug, array $submittedData): array
    {
        $gateway = $this->gateways->forTenant($merchantId)->findBySlug($slug);
        if ($gateway === null) {
            return ['valid' => false, 'error' => 'Gateway not found'];
        }

        $inputFields = json_decode($gateway['input_fields'] ?? '[]', true);
        $errors = [];

        // Validate required fields
        foreach ($inputFields as $field) {
            $name = $field['name'] ?? '';
            $required = $field['required'] ?? false;
            if ($required && empty($submittedData[$name] ?? '')) {
                $errors[] = "Field '{$field['label']}' is required";
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Plugin filter for custom verification
        $result = $this->events->applyFilter('gateway.manual.verify', ['valid' => true], $gateway, $submittedData);

        return $result;
    }

    /**
     * List active manual gateways for checkout.
     */
    public function listForCheckout(int $merchantId): array
    {
        return $this->gateways->forTenant($merchantId)->listActive();
    }
}
