<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Support\DateHelper;

/**
 * Mobile Config API — returns dynamic filter rules for the mobile companion app.
 *
 * GET /api/mobile/v1/config/filter-rules
 *
 * The mobile app uses this response to:
 *  - Know which SMS senders (From field) are whitelisted
 *  - Filter positive/negative keywords before sending to server
 *  - Know how often to refresh this config
 *
 * OWASP: Requires JWT auth (MobileAuthMiddleware). Brand-scoped.
 */
final class ConfigController
{
    private SmsTemplateRepository $smsTemplates;
    private SettingsRepository $settings;

    public function __construct(Container $c, SmsTemplateRepository $smsTemplates, SettingsRepository $settings)
    {
        $this->smsTemplates = $smsTemplates;
        $this->settings     = $settings;
    }

    /**
     * GET /api/mobile/v1/config/filter-rules
     *
     * Returns sender whitelist + keyword filters for the mobile SMS privacy gate.
     */
    public function filterRules(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');

        // Collect unique active sender patterns for this brand (the whitelist)
        $templates      = $this->smsTemplates->forTenant($mid)->listActive();
        $allowedSenders = [];
        foreach ($templates as $tpl) {
            $sender = trim($tpl['sender_pattern'] ?? '');
            if ($sender !== '' && !in_array($sender, $allowedSenders, true)) {
                $allowedSenders[] = $sender;
            }
        }

        // Positive / negative keywords from system settings (admin-configurable)
        $positiveRaw = $this->settings->get('sms', 'positive_keywords', '');
        $negativeRaw = $this->settings->get('sms', 'negative_keywords', '');

        $positiveKeywords = $this->parseKeywords($positiveRaw, [
            'received', 'credited', 'TrxID', 'TxnID', 'deposited', 'Tk', 'BDT',
        ]);
        $negativeKeywords = $this->parseKeywords($negativeRaw, [
            'OTP', 'PIN', 'password', 'verify', 'verification', 'code',
        ]);

        $checkInterval = (int) $this->settings->get('sms', 'filter_rules_check_interval_hours', '24');

        return Response::json([
            'success'               => true,
            'version'               => 1,
            'updated_at'            => DateHelper::iso(),
            'allowed_senders'       => array_values($allowedSenders),
            'positive_keywords'     => $positiveKeywords,
            'negative_keywords'     => $negativeKeywords,
            'check_interval_hours'  => max(1, $checkInterval),
        ]);
    }

    /**
     * Parse a newline/comma separated keyword string into array.
     * Falls back to $defaults if setting is empty.
     *
     * @param  string[] $defaults
     * @return string[]
     */
    private function parseKeywords(string $raw, array $defaults): array
    {
        if (trim($raw) === '') {
            return $defaults;
        }
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out ?: $defaults;
    }
}
