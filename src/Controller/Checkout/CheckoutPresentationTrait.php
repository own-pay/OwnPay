<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

/**
 * Shared presentation helpers for customer-facing checkout controllers.
 *
 * Consolidates brand theme resolution and transaction status labeling that were
 * previously duplicated (and drifting) between CheckoutController and
 * PaymentIntentCheckoutController.
 *
 * Host classes must expose `$c` (Container), `$merchants` (MerchantRepository)
 * and `$settings` (SettingsRepository) properties.
 */
trait CheckoutPresentationTrait
{
    /**
     * Human-readable labels for every customer-visible transaction state.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'success'               => 'Payment Successful',
        'completed'             => 'Payment Successful',
        'failed'                => 'Payment Failed',
        'cancelled'             => 'Payment Cancelled',
        'canceled'              => 'Payment Cancelled',
        'expired'               => 'Payment Expired',
        'pending'               => 'Payment Pending',
        'pending_review'        => 'Payment Under Review',
        'awaiting_verification' => 'Awaiting Verification',
        'processing'            => 'Payment Processing',
        'callback_processing'   => 'Payment Processing',
    ];

    /**
     * Maps a transaction status code to its customer-facing label.
     *
     * @param string $status Transaction status code.
     * @return string Friendly display label.
     */
    private function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Resolves theme styling configuration and brand visual assets for a merchant.
     *
     * Uses BrandThemeService for full white-label per-brand theming, falling back
     * to merchant record + global settings when the service is unavailable.
     *
     * @param int $mid The merchant/brand identifier.
     * @return array<string, mixed> Brand visual style settings.
     */
    private function loadBrand(int $mid): array
    {
        if ($this->c->has(\OwnPay\Service\Brand\BrandThemeService::class)) {
            $themeSvc = $this->c->get(\OwnPay\Service\Brand\BrandThemeService::class);
            if ($themeSvc instanceof \OwnPay\Service\Brand\BrandThemeService) {
                return $themeSvc->getBrandTheme($mid);
            }
        }

        // Fallback: resolve basic branding from merchant record and core settings.
        $merchant = $this->merchants->find($mid);
        $s = $this->settings->getGroup('general');
        $theme = $this->settings->getGroup('theme');
        return [
            'name'          => ($merchant !== null && isset($merchant['name']) && is_string($merchant['name'])) ? $merchant['name'] : ($s['app_name'] ?? 'OwnPay'),
            'logo'          => ($merchant !== null && isset($merchant['logo']) && is_string($merchant['logo'])) ? $merchant['logo'] : '',
            'color'         => $theme['primary_color'] ?? ($s['theme_primary'] ?? '#0D9488'),
            'support_email' => $s['support_email'] ?? '',
            'language'      => '',
            'checkout_success_msg' => '',
            'checkout_pending_msg' => '',
            'checkout_failed_msg'  => '',
        ];
    }
}
