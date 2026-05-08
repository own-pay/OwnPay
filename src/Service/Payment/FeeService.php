<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * Fee service â€” calculates transaction fees.
 *
 * Fires: payment.fee.calculate filter
 */
final class FeeService
{
    private \OwnPay\Event\EventManager $events;
    private \OwnPay\Repository\SettingsRepository $settings;

    public function __construct(
        \OwnPay\Event\EventManager $events,
        \OwnPay\Repository\SettingsRepository $settings
    ) {
        $this->events = $events;
        $this->settings = $settings;
    }

    /**
     * Calculate fee for amount.
     *
     * Fee = max(fixed_fee, percentage_fee * amount)
     * Plugins can override via filter.
     */
    public function calculate(string $amount, string $currency, string $gatewaySlug, int $merchantId): string
    {
        $feeConfig = $this->getFeeConfig($gatewaySlug);

        $percentFee = bcmul($amount, bcdiv($feeConfig['percentage'], '100', 6), 2);
        $fixedFee = $feeConfig['fixed'];

        // Use whichever is greater, or sum
        $fee = match ($feeConfig['mode']) {
            'sum'     => bcadd($percentFee, $fixedFee, 2),
            'greater' => bccomp($percentFee, $fixedFee, 2) >= 0 ? $percentFee : $fixedFee,
            default   => bcadd($percentFee, $fixedFee, 2),
        };

        // Min/max cap
        if (bccomp($fee, $feeConfig['min'], 2) < 0) {
            $fee = $feeConfig['min'];
        }
        if ($feeConfig['max'] !== '0.00' && bccomp($fee, $feeConfig['max'], 2) > 0) {
            $fee = $feeConfig['max'];
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
     * @return array{percentage: string, fixed: string, min: string, max: string, mode: string}
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
