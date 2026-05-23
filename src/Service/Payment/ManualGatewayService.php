<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\ManualGatewayRepository;

/**
 * Service managing custom manual/offline payment gateways.
 *
 * Handles gateways such as cash on delivery, bank transfer, and MFS options where
 * payments are manually verified, resolving display metadata and field validation requirements.
 */
final class ManualGatewayService
{
    /**
     * @var ManualGatewayRepository Repository accessing manual gateway models.
     */
    private ManualGatewayRepository $gateways;

    /**
     * @var EventManager Event dispatcher for action/filter hooks.
     */
    private EventManager $events;

    /**
     * ManualGatewayService constructor.
     *
     * @param ManualGatewayRepository $gateways Repository for manual gateway database operations.
     * @param EventManager $events Event dispatcher for system hooks.
     */
    public function __construct(ManualGatewayRepository $gateways, EventManager $events)
    {
        $this->gateways = $gateways;
        $this->events = $events;
    }

    /**
     * Prepares manual gateway configuration and input rules for checkout forms.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param string $slug The unique slug identifier of the manual gateway.
     * @return array<string, mixed>|null Form rendering config, or null if the gateway is invalid/inactive.
     */
    public function getFormData(int $merchantId, string $slug): ?array
    {
        $gateway = $this->gateways->forTenant($merchantId)->findBySlug($slug);
        if ($gateway === null || $gateway['status'] !== 'active') {
            return null;
        }

        $inputFieldsVal = $gateway['input_fields'] ?? '[]';
        $colorsVal = $gateway['colors'] ?? '{}';

        $formData = [
            'name'         => $gateway['name'],
            'slug'         => $gateway['slug'],
            'logo_path'    => $gateway['logo_path'],
            'qr_code_path' => $gateway['qr_code_path'],
            'instructions' => $gateway['instructions'],
            'input_fields' => json_decode(is_string($inputFieldsVal) ? $inputFieldsVal : '[]', true),
            'colors'       => json_decode(is_string($colorsVal) ? $colorsVal : '{}', true),
            'min_amount'   => $gateway['min_amount'],
            'max_amount'   => $gateway['max_amount'],
            'sms_verification' => (bool) $gateway['sms_verification'],
        ];

        // Plugin filter for custom form rendering
        $res = $this->events->applyFilter('gateway.manual.render', $formData, $gateway);
        if (is_array($res)) {
            $mapped = [];
            foreach ($res as $k => $v) {
                $mapped[(string)$k] = $v;
            }
            return $mapped;
        }
        return null;
    }

    /**
     * Verifies submitted manual checkout data against the defined gateway fields.
     *
     * Runs basic requirements validation, then passes variables through custom
     * plugin verification hooks.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param string $slug The unique slug identifier of the manual gateway.
     * @param array<string, mixed> $submittedData The user-submitted checkout payload parameters.
     * @return array{valid: bool, error?: string, errors?: array<int, string>} Validation outcome.
     */
    public function verify(int $merchantId, string $slug, array $submittedData): array
    {
        $gateway = $this->gateways->forTenant($merchantId)->findBySlug($slug);
        if ($gateway === null) {
            return ['valid' => false, 'error' => 'Gateway not found'];
        }

        $inputFieldsVal = $gateway['input_fields'] ?? '[]';
        $inputFields = json_decode(is_string($inputFieldsVal) ? $inputFieldsVal : '[]', true);
        $errors = [];

        if (!is_array($inputFields)) {
            $inputFields = [];
        }

        // Validate required fields
        foreach ($inputFields as $field) {
            if (is_array($field)) {
                $nameVal = $field['name'] ?? '';
                $name = is_scalar($nameVal) ? (string) $nameVal : '';
                $required = (bool) ($field['required'] ?? false);
                if ($name !== '' && $required && empty($submittedData[$name] ?? '')) {
                    $labelVal = $field['label'] ?? '';
                    $label = is_scalar($labelVal) ? (string) $labelVal : '';
                    $errors[] = "Field '{$label}' is required";
                }
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Plugin filter for custom verification
        $res = $this->events->applyFilter('gateway.manual.verify', ['valid' => true], $gateway, $submittedData);
        if (is_array($res)) {
            $valid = (bool) ($res['valid'] ?? false);
            $error = is_scalar($res['error'] ?? null) ? (string) $res['error'] : null;
            $rawErrors = $res['errors'] ?? null;
            $errorsList = [];
            if (is_array($rawErrors)) {
                foreach ($rawErrors as $err) {
                    if (is_scalar($err)) {
                        $errorsList[] = (string) $err;
                    }
                }
            }
            $returnVal = ['valid' => $valid];
            if ($error !== null) {
                $returnVal['error'] = $error;
            }
            if (!empty($errorsList)) {
                $returnVal['errors'] = $errorsList;
            }
            return $returnVal;
        }

        return ['valid' => false, 'error' => 'Invalid verification result'];
    }

    /**
     * Lists active manual payment gateways available for merchant checkout pages.
     *
     * @param int $merchantId The brand/merchant ID.
     * @return array<int, array<string, mixed>> List of active manual gateways.
     */
    public function listForCheckout(int $merchantId): array
    {
        return $this->gateways->forTenant($merchantId)->listActive();
    }
}
