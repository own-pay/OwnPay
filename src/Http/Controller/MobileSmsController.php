<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\JwtAuthMiddleware;
use OwnPay\Service\Sms\SmsParserService;

/**
 * MobileSmsController — REST API endpoints for SMS submission & filter rules.
 *
 * Endpoints:
 *   POST /v1/sms/submit         — Encrypted batch SMS submission (max 20 per request)
 *   GET  /v1/config/filter-rules — Get sender/keyword filter config for the app
 *
 * All endpoints require JWT auth + X-Device-Fingerprint.
 */
final class MobileSmsController
{
    private SmsParserService $parser;

    public function __construct()
    {
        $this->parser = new SmsParserService();
    }

    /**
     * POST /v1/sms/submit
     *
     * Accepts a batch of encrypted SMS payloads from the companion app.
     *
     * Request body:
     * {
     *   "messages": [
     *     {
     *       "local_id": 42,
     *       "encrypted_payload": "<aes_ciphertext_base64>",
     *       "sender": "bKash",
     *       "received_at": "2026-04-27T10:30:00+06:00"
     *     }
     *   ]
     * }
     *
     * Response (200):
     * {
     *   "results": [
     *     { "local_id": 42, "status": "accepted", "server_ref": "sms_123" }
     *   ]
     * }
     */
    public function submit(array $params): void
    {
        // Auth: JWT + fingerprint + scope check
        $device = (new JwtAuthMiddleware())->guard('sms:submit');

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $messages = $body['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) {
            JsonResponse::error('MISSING_MESSAGES', 'The "messages" array is required and must not be empty.', 400);
            return;
        }

        // Cap at 20 messages per request
        if (count($messages) > 20) {
            JsonResponse::error('BATCH_TOO_LARGE', 'Maximum 20 messages per request.', 400);
            return;
        }

        // Validate each message structure
        foreach ($messages as $i => $msg) {
            if (!is_array($msg)) {
                JsonResponse::error('INVALID_MESSAGE', "Message at index {$i} is not a valid object.", 400);
                return;
            }
            if (empty($msg['encrypted_payload']) || empty($msg['sender'])) {
                JsonResponse::error(
                    'INVALID_MESSAGE',
                    "Message at index {$i} is missing required fields (encrypted_payload, sender).",
                    400
                );
                return;
            }
        }

        // Process batch
        $results = $this->parser->processBatch(
            $device['device_uuid'],
            (int) $device['brand_id'],
            $messages
        );

        JsonResponse::success(['results' => $results]);
    }

    /**
     * GET /v1/config/filter-rules
     *
     * Returns the SMS filter configuration for the companion app.
     * App caches locally and re-fetches every 24h.
     *
     * Response:
     * {
     *   "version": 1,
     *   "updated_at": "2026-04-27T10:00:00Z",
     *   "allowed_senders": ["bKash", "16247", "Nagad", ...],
     *   "positive_keywords": ["received", "TrxID", "credited", ...],
     *   "negative_keywords": ["OTP", "PIN", "password", ...],
     *   "check_interval_hours": 24
     * }
     */
    public function filterRules(array $params): void
    {
        // Auth: JWT + fingerprint (no specific scope required)
        (new JwtAuthMiddleware())->guard();

        // Static rules — can be extended to pull from DB/system_settings later
        JsonResponse::success([
            'version'       => 1,
            'updated_at'    => date('c'),
            'allowed_senders' => [
                'bKash', '16247',   // bKash sender IDs
                'Nagad',            // Nagad
                '16216',            // Rocket (DBBL)
                'Upay',             // Upay
                'SureCash',         // SureCash
                'DBBL',             // Dutch-Bangla Bank
                'EBL',              // Eastern Bank
            ],
            'positive_keywords' => [
                'received', 'TrxID', 'TxnID', 'credited', 'debited',
                'Tk', 'BDT', 'Taka', 'deposited', 'Cash In', 'Cash Out',
                'sent', 'payment', 'balance', 'withdrawn',
            ],
            'negative_keywords' => [
                'OTP', 'PIN', 'password', 'verify', 'verification',
                'code', 'reset', 'login', 'activate', 'confirm your',
            ],
            'check_interval_hours' => 24,
        ]);
    }
}
