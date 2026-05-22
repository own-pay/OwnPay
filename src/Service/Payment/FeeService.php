<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\FeeRuleRepository;

/**
 * Manages the calculation of transaction processing fees.
 *
 * Resolves fee rules based on system-wide settings or brand-specific
 * overrides, and applies filters allowing external plugins to modify fees dynamically.
 */
final class FeeService
{
    /**
     * @var EventManager The system-wide event manager for plugin action/filter execution.
     */
    private EventManager $events;

    /**
     * @var SettingsRepository The repository for managing site settings.
     */
    private SettingsRepository $settings;
    private FeeRuleRepository $feeRuleRepo;

    /**
     * FeeService constructor.
     *
     * @param EventManager $events System event dispatcher.
     * @param SettingsRepository $settings Settings storage interface.
     * @param Database $db Direct database query runner.
     */
    public function __construct(
        EventManager $events,
        SettingsRepository $settings,
        FeeRuleRepository $feeRuleRepo
    ) {
        $this->events = $events;
        $this->settings = $settings;
        $this->feeRuleRepo = $feeRuleRepo;
    }

    /**
     * Calculates the processing fee for a given amount, currency, and gateway.
     *
     * Evaluates active fee rules ordered by specificity. Falls back to default gateway settings
     * if no custom rules match. Lastly, applies the `payment.fee.calculate` filter hook.
     *
     * @param string $amount The transaction amount as a decimal string.
     * @param string $currency The ISO 4217 three-letter currency code.
     * @param string $gatewaySlug The slug identifying the payment gateway.
     * @param int $merchantId The unique identifier of the brand/merchant.
     * @return string The calculated fee amount as a decimal string.
     */
    public function calculate(string $amount, string $currency, string $gatewaySlug, int $merchantId): string
    {
        // 1. Resolve custom fee rules from the op_fee_rules table
        $rule = $this->resolveActiveRule($merchantId, $gatewaySlug, $currency);

        if ($rule !== null) {
            $fee = $this->calculateRuleFee($amount, $rule);
        } else {
            // Fall back to default settings config
            $feeConfig = $this->getFeeConfig($gatewaySlug);

            $percentFee = bcmul($amount, bcdiv($feeConfig['percentage'], '100', 6), 2);
            $fixedFee = $feeConfig['fixed'];

            // Use whichever is greater, or sum
            $fee = match ($feeConfig['mode']) {
                'sum'     => bcadd($percentFee, $fixedFee, 2),
                'greater' => bccomp($percentFee, $fixedFee, 2) >= 0 ? $percentFee : $fixedFee,
                default   => bcadd($percentFee, $fixedFee, 2),
            };

            // Min/max cap for default config
            if (bccomp($fee, $feeConfig['min'], 2) < 0) {
                $fee = $feeConfig['min'];
            }
            if ($feeConfig['max'] !== '0.00' && bccomp($fee, $feeConfig['max'], 2) > 0) {
                $fee = $feeConfig['max'];
            }
        }

        // Plugin filter
        $fee = $this->events->applyFilter('payment.fee.calculate', $fee, [
            'amount'      => $amount,
            'currency'    => $currency,
            'gateway'     => $gatewaySlug,
            'merchant_id' => $merchantId,
        ]);

        return $fee;
    }

    /**
     * Resolves the active fee rule prioritised by specificity.
     *
     * Rules are sorted so that merchant-and-gateway specific rules take priority,
     * followed by merchant-only rules, gateway-only rules, and lastly global rules.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param string $gatewaySlug The gateway slug identifier.
     * @param string $currency The transaction currency code.
     * @return array<string, mixed>|null The matched fee rule row, or null if none matches.
     */
    private function resolveActiveRule(int $merchantId, string $gatewaySlug, string $currency): ?array
    {
        return $this->feeRuleRepo->resolveActiveRule($merchantId, $gatewaySlug, $currency);
    }

    /**
     * Calculates the fee using a custom active rule.
     *
     * Supports flat, percentage, and tiered fee structures, applying min/max caps.
     *
     * @param string $amount The transaction amount.
     * @param array<string, mixed> $rule The fee rule database configuration row.
     * @return string The calculated fee decimal string.
     */
    private function calculateRuleFee(string $amount, array $rule): string
    {
        $type = $rule['type'];
        $value = (string) $rule['value'];

        if ($type === 'flat') {
            $fee = $value;
        } elseif ($type === 'percentage') {
            $fee = bcmul($amount, bcdiv($value, '100', 6), 2);
        } elseif ($type === 'tiered') {
            $tiers = null;
            if (is_string($rule['tiers'])) {
                $tiers = json_decode($rule['tiers'], true);
            } elseif (is_array($rule['tiers'])) {
                $tiers = $rule['tiers'];
            }

            if (!is_array($tiers)) {
                $fee = '0.00';
            } else {
                // Sort tiers by limit ascending
                usort($tiers, static function (array $a, array $b) {
                    $limA = $a['limit'] ?? null;
                    $limB = $b['limit'] ?? null;
                    if ($limA === null && $limB === null) {
                        return 0;
                    }
                    if ($limA === null) {
                        return 1;
                    }
                    if ($limB === null) {
                        return -1;
                    }
                    return bccomp((string) $limA, (string) $limB, 4);
                });

                // Find matching tier
                $matchedTier = null;
                foreach ($tiers as $tier) {
                    $limit = $tier['limit'] ?? null;
                    if ($limit === null || bccomp($amount, (string) $limit, 4) <= 0) {
                        $matchedTier = $tier;
                        break;
                    }
                }

                if ($matchedTier !== null) {
                    $tierType = $matchedTier['type'] ?? 'percentage';
                    $tierValue = (string) ($matchedTier['value'] ?? '0.00');

                    if ($tierType === 'flat') {
                        $fee = $tierValue;
                    } else {
                        // percentage
                        $fee = bcmul($amount, bcdiv($tierValue, '100', 6), 2);
                    }
                } else {
                    $fee = '0.00';
                }
            }
        } else {
            $fee = '0.00';
        }

        // Apply min/max caps from rule
        if ($rule['min_fee'] !== null && bccomp($fee, (string) $rule['min_fee'], 2) < 0) {
            $fee = (string) $rule['min_fee'];
        }
        if ($rule['max_fee'] !== null && bccomp((string) $rule['max_fee'], '0.00', 2) > 0 && bccomp($fee, (string) $rule['max_fee'], 2) > 0) {
            $fee = (string) $rule['max_fee'];
        }

        return bcadd($fee, '0', 2);
    }

    /**
     * Retrieves the default system settings for a specific gateway's fees.
     *
     * @param string $gatewaySlug The identifier of the payment gateway.
     * @return array{percentage: string, fixed: string, min: string, max: string, mode: string} Gateway fee setting values.
     */
    private function getFeeConfig(string $gatewaySlug): array
    {
        return [
            'percentage' => $this->settings->get('fees', "{$gatewaySlug}.percentage", '2.50'),
            'fixed'      => $this->settings->get('fees', "{$gatewaySlug}.fixed", '0.00'),
            'min'        => $this->settings->get('fees', "{$gatewaySlug}.min", '0.00'),
            'max'        => $this->settings->get('fees', "{$gatewaySlug}.max", '0.00'),
            'mode'       => $this->settings->get('fees', "{$gatewaySlug}.mode", 'sum'),
        ];
    }
}
