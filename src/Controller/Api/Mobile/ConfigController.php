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
 * Class ConfigController
 *
 * Mobile Config API - returns dynamic filter rules for the mobile companion app.
 * The mobile app uses this response to:
 *  - Know which SMS senders (From field) are whitelisted
 *  - Filter positive/negative keywords before sending to server
 *  - Know how often to refresh this config
 *
 * OWASP: Requires JWT auth (MobileAuthMiddleware). Brand-scoped.
 *
 * @package OwnPay\Controller\Api\Mobile
 */
final class ConfigController
{
    /**
     * @var SmsTemplateRepository The SMS template repository.
     */
    private SmsTemplateRepository $smsTemplates;

    /**
     * @var SettingsRepository The settings repository.
     */
    private SettingsRepository $settings;

    /**
     * ConfigController constructor.
     *
     * @param SmsTemplateRepository $smsTemplates The SMS template repository.
     * @param SettingsRepository    $settings     The settings repository.
     */
    public function __construct(SmsTemplateRepository $smsTemplates, SettingsRepository $settings)
    {
        $this->smsTemplates = $smsTemplates;
        $this->settings     = $settings;
    }

    /**
     * Retrieves the sender whitelist + keyword filters for the mobile SMS privacy gate.
     *
     * GET /api/mobile/v1/config/filter-rules
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with filter rules config.
     */
    public function filterRules(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $templates      = $this->smsTemplates->listActiveForDevice($mid);
        $allowedSenders = [];
        foreach ($templates as $tpl) {
            $senderVal = $tpl['sender_pattern'] ?? '';
            $sender = trim(is_string($senderVal) ? $senderVal : '');
            if ($sender !== '' && !in_array($sender, $allowedSenders, true)) {
                $allowedSenders[] = $sender;
            }
        }

        // Positive / negative keywords from system settings (admin-configurable)
        $positiveRaw = (string) $this->settings->get('sms', 'positive_keywords', '');
        $negativeRaw = (string) $this->settings->get('sms', 'negative_keywords', '');

        $positiveKeywords = $this->parseKeywords($positiveRaw, [
            'received', 'credited', 'TrxID', 'TxnID', 'deposited', 'Tk', 'BDT',
        ]);
        $negativeKeywords = $this->parseKeywords($negativeRaw, [
            'OTP', 'PIN', 'password', 'verify', 'verification', 'code',
        ]);

        $checkInterval = (int) $this->settings->get('sms', 'filter_rules_check_interval_hours', '24');

        $data = [
            'version'               => 1,
            'updated_at'            => DateHelper::iso(),
            'allowed_senders'       => $allowedSenders,
            'positive_keywords'     => $positiveKeywords,
            'negative_keywords'     => $negativeKeywords,
            'check_interval_hours'  => max(1, $checkInterval),
        ];

        return Response::apiSuccess($data);
    }

    /**
     * Parses a newline/comma separated keyword string into an array.
     * Falls back to default list if setting is empty.
     *
     * @param string   $raw      The raw settings string.
     * @param string[] $defaults The fallback defaults list.
     * @return string[] The parsed keywords array.
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
