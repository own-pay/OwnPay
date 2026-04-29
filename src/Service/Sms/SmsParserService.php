<?php

declare(strict_types=1);

namespace OwnPay\Service\Sms;

use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Security\FieldEncryptor;

/**
 * SmsParserService — Orchestrator for the 2-tier SMS parsing engine.
 *
 * Flow per message:
 *   1. Retrieve device's AES-256 key from op_paired_devices (decrypted via FieldEncryptor)
 *   2. Decrypt the SMS payload using the device's AES key
 *   3. Dedup check (same device + sender + received_at within 1s window)
 *   4. Tier 1: Try regex templates matching the sender
 *   5. Tier 2: Heuristic lexical analysis (fallback)
 *   6. Save to op_sms_parsed with appropriate status/confidence
 *
 * Constructor accepts mixed types to allow test stub injection (same pattern as DevicePairingService).
 */
final class SmsParserService
{
    private mixed $deviceRepo;
    private mixed $templateRepo;
    private mixed $dataRepo;
    private SmsRegexParser $regexParser;
    private SmsHeuristicParser $heuristicParser;
    private mixed $encryptor;
    private mixed $notifService;

    public function __construct(
        mixed $deviceRepo = null,
        mixed $templateRepo = null,
        mixed $dataRepo = null,
        ?SmsRegexParser $regexParser = null,
        ?SmsHeuristicParser $heuristicParser = null,
        mixed $encryptor = null,
        mixed $notifService = null,
    ) {
        $this->deviceRepo      = $deviceRepo      ?? new PairedDeviceRepository();
        $this->templateRepo    = $templateRepo     ?? new SmsTemplateRepository();
        $this->dataRepo        = $dataRepo         ?? new SmsDataRepository();
        $this->regexParser     = $regexParser      ?? new SmsRegexParser();
        $this->heuristicParser = $heuristicParser  ?? new SmsHeuristicParser();
        $this->encryptor       = $encryptor        ?? new FieldEncryptor();
        $this->notifService    = $notifService     ?? new MobileNotificationService();
    }

    /**
     * Process a batch of SMS messages from a mobile device.
     *
     * @param string $deviceUuid  Authenticated device UUID
     * @param int    $brandId     Brand/merchant ID from JWT claims
     * @param array  $messages    Array of message payloads:
     *                            [{local_id, encrypted_payload, sender, received_at}, ...]
     * @return array Results per message: [{local_id, status, server_ref, error?}, ...]
     */
    public function processBatch(string $deviceUuid, int $brandId, array $messages): array
    {
        // 1. Look up device to get AES key
        $device = $this->deviceRepo->findByUuid($deviceUuid);
        if ($device === null) {
            return array_map(fn ($m) => [
                'local_id'   => $m['local_id'] ?? null,
                'status'     => 'rejected',
                'server_ref' => null,
                'error'      => 'DEVICE_NOT_FOUND',
            ], $messages);
        }

        // 2. Decrypt the device's stored AES key
        $deviceAesKey = null;
        try {
            $deviceAesKey = $this->encryptor->decrypt($device['aes_key_encrypted']);
        } catch (\Throwable) {
            return array_map(fn ($m) => [
                'local_id'   => $m['local_id'] ?? null,
                'status'     => 'rejected',
                'server_ref' => null,
                'error'      => 'KEY_DECRYPTION_FAILED',
            ], $messages);
        }

        // 3. Process each message
        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->processOne($deviceUuid, $brandId, $deviceAesKey, $msg);
        }

        return $results;
    }

    /**
     * Process a single SMS message.
     *
     * @return array{local_id: ?int, status: string, server_ref: ?string, error?: string}
     */
    private function processOne(string $deviceUuid, int $brandId, string $aesKeyHex, array $msg): array
    {
        $localId        = $msg['local_id'] ?? null;
        $encryptedPayload = $msg['encrypted_payload'] ?? '';
        $sender         = trim($msg['sender'] ?? '');
        $receivedAt     = $msg['received_at'] ?? date('Y-m-d H:i:s');

        // Validate required fields
        if ($encryptedPayload === '' || $sender === '') {
            return [
                'local_id'   => $localId,
                'status'     => 'rejected',
                'server_ref' => null,
                'error'      => 'MISSING_FIELDS',
            ];
        }

        // Normalize received_at to MySQL datetime
        $receivedAt = $this->normalizeTimestamp($receivedAt);

        // Dedup check
        if ($this->dataRepo->isDuplicate($deviceUuid, $sender, $receivedAt)) {
            return [
                'local_id'   => $localId,
                'status'     => 'duplicate',
                'server_ref' => null,
            ];
        }

        // Decrypt SMS payload
        $rawMessage = null;
        try {
            $rawMessage = $this->decryptSmsPayload($encryptedPayload, $aesKeyHex);
        } catch (\Throwable $e) {
            // Store as unparsed with encrypted_raw for admin review
            $id = $this->dataRepo->create([
                'device_uuid'      => $deviceUuid,
                'brand_id'         => $brandId,
                'local_id'         => $localId,
                'sender'           => $sender,
                'received_at'      => $receivedAt,
                'encrypted_raw'    => $encryptedPayload,
                'raw_message'      => null,
                'parsed_type'      => 'unknown',
                'parse_method'     => 'unparsed',
                'parse_confidence' => 'low',
                'status'           => 'parse_error',
            ]);

            return [
                'local_id'   => $localId,
                'status'     => 'accepted',
                'server_ref' => 'sms_' . $id,
                'error'      => 'DECRYPTION_FAILED',
            ];
        }

        // Tier 1: Regex templates
        $templates = $this->templateRepo->findBySender($sender);
        $templates = apply_filters('mfs.templates', $templates);
        $parsed = $this->regexParser->parse($rawMessage, $templates);

        // Tier 2: Heuristic fallback
        if ($parsed === null) {
            $parsed = $this->heuristicParser->parse($rawMessage);
        }

        // Build record
        $record = [
            'device_uuid'      => $deviceUuid,
            'brand_id'         => $brandId,
            'local_id'         => $localId,
            'sender'           => $sender,
            'received_at'      => $receivedAt,
            'encrypted_raw'    => $encryptedPayload,
            'raw_message'      => $rawMessage,
            'parsed_amount'    => $parsed['parsed_amount'] ?? null,
            'parsed_trx_id'    => $parsed['parsed_trx_id'] ?? null,
            'parsed_sender'    => $parsed['parsed_sender'] ?? null,
            'parsed_balance'   => $parsed['parsed_balance'] ?? null,
            'parsed_type'      => $parsed['parsed_type'] ?? 'unknown',
            'parse_method'     => $parsed['parse_method'] ?? 'unparsed',
            'template_id'      => $parsed['template_id'] ?? null,
            'parse_confidence' => $parsed['parse_confidence'] ?? 'low',
            'status'           => ($parsed === null) ? 'admin_review' : 'accepted',
        ];

        $id = $this->dataRepo->create($record);

        // Queue notification for device if parsed successfully
        if ($parsed !== null && ($parsed['parsed_amount'] ?? null) !== null) {
            try {
                $this->notifService->queuePaymentNotification(
                    $deviceUuid,
                    $parsed['parsed_type'] ?? 'unknown',
                    $parsed['parsed_amount'] ?? null,
                    $parsed['parsed_sender'] ?? null,
                    $parsed['parsed_trx_id'] ?? null,
                    $sender,
                );
            } catch (\Throwable) {
                // Notification failure must not break SMS processing
            }
        }

        return [
            'local_id'   => $localId,
            'status'     => 'accepted',
            'server_ref' => 'sms_' . $id,
        ];
    }

    /**
     * Decrypt an SMS payload encrypted with the device's AES-256 key.
     *
     * Expected format: base64(IV + ciphertext + auth_tag)
     * The device encrypts with AES-256-GCM using the shared key.
     *
     * @param string $encryptedB64 Base64-encoded encrypted payload
     * @param string $aesKeyHex    64-char hex device AES key
     * @return string Decrypted plaintext
     * @throws \RuntimeException On decryption failure
     */
    private function decryptSmsPayload(string $encryptedB64, string $aesKeyHex): string
    {
        $raw = base64_decode($encryptedB64, true);
        if ($raw === false || strlen($raw) < 28) { // 12 IV + 16 tag minimum
            throw new \RuntimeException('Invalid encrypted payload format.');
        }

        $key = hex2bin($aesKeyHex);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Invalid AES key.');
        }

        // AES-256-GCM: IV(12) + ciphertext(variable) + tag(16)
        $ivLen = 12;
        $tagLen = 16;

        $iv         = substr($raw, 0, $ivLen);
        $tag        = substr($raw, -$tagLen);
        $ciphertext = substr($raw, $ivLen, strlen($raw) - $ivLen - $tagLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('AES-256-GCM decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Normalize an ISO 8601 timestamp to MySQL DATETIME format.
     */
    private function normalizeTimestamp(string $ts): string
    {
        try {
            $dt = new \DateTimeImmutable($ts);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s');
        }
    }
}
