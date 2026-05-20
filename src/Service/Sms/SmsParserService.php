<?php

declare(strict_types=1);

namespace OwnPay\Service\Sms;

use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Notification\MobileNotificationService;
use OwnPay\Service\System\Logger;
use OwnPay\Event\EventManager;
use OwnPay\Support\DateHelper;

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
    private $deviceRepo;
    private $templateRepo;
    private $dataRepo;
    private $regexParser;
    private $heuristicParser;
    private $encryptor;
    private $notifService;
    private $events;
    private $logger;

    public function __construct(
        $deviceRepo,
        $templateRepo,
        $dataRepo,
        $regexParser,
        $heuristicParser,
        $encryptor,
        $notifService,
        $events = null,
        $logger = null
    ) {
        $this->deviceRepo      = $deviceRepo;
        $this->templateRepo    = $templateRepo;
        $this->dataRepo        = $dataRepo;
        $this->regexParser     = $regexParser;
        $this->heuristicParser = $heuristicParser;
        $this->encryptor       = $encryptor;
        $this->notifService    = $notifService;
        $this->events          = $events ?? EventManager::getInstance();
        $this->logger          = $logger ?? new Logger();
    }

    /**
     * Process a batch of SMS messages from a mobile device.
     */
    public function processBatch(string $deviceUuid, int $brandId, array $messages): array
    {
        $device = $this->deviceRepo->findByUuid($deviceUuid);
        if ($device === null) {
            return $this->rejectAll($messages, 'DEVICE_NOT_FOUND');
        }

        try {
            $deviceAesKey = $this->encryptor->decrypt($device['aes_key_encrypted']);
        } catch (\Throwable) {
            return $this->rejectAll($messages, 'KEY_DECRYPTION_FAILED');
        }

        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->processOne($deviceUuid, $brandId, $deviceAesKey, $msg);
        }
        return $results;
    }

    /**
     * Process a single SMS message.
     * Decomposed into: validate → dedup → decrypt → parse → store → notify.
     */
    private function processOne(string $deviceUuid, int $brandId, string $aesKeyHex, array $msg): array
    {
        $localId    = $msg['local_id'] ?? null;
        $encrypted  = $msg['encrypted_payload'] ?? '';
        $sender     = trim($msg['sender'] ?? '');
        $receivedAt = $this->normalizeTimestamp($msg['received_at'] ?? DateHelper::now());

        // Step 1: Validate
        if ($encrypted === '' || $sender === '') {
            return $this->makeResult($localId, 'rejected', null, 'MISSING_FIELDS');
        }

        // Step 2: Dedup
        if ($this->dataRepo->isDuplicate($deviceUuid, $sender, $receivedAt)) {
            return $this->makeResult($localId, 'duplicate');
        }

        // Step 3: Decrypt
        $rawMessage = $this->tryDecrypt($encrypted, $aesKeyHex);
        if ($rawMessage === null) {
            $id = $this->storeFailedDecryption($deviceUuid, $brandId, $localId, $sender, $receivedAt, $encrypted);
            return $this->makeResult($localId, 'accepted', 'sms_' . $id, 'DECRYPTION_FAILED');
        }

        // Step 4: Parse (sender whitelist gate → tier 1 regex → tier 2 heuristic)
        $parsed = $this->attemptParse($rawMessage, $sender, $brandId);

        // Step 5: Store
        $record = $this->buildRecord($deviceUuid, $brandId, $localId, $sender, $receivedAt, $encrypted, $rawMessage, $parsed);
        $id = $this->dataRepo->create($record);

        // Step 6: Notify
        $this->notifyDevice($parsed, $deviceUuid, $sender);

        return $this->makeResult($localId, 'accepted', 'sms_' . $id);
    }

    // ── Extracted Methods ─────────────────────────────────────────

    /**
     * Reject all messages in a batch with same error.
     */
    private function rejectAll(array $messages, string $error): array
    {
        return array_map(fn($m) => $this->makeResult($m['local_id'] ?? null, 'rejected', null, $error), $messages);
    }

    /**
     * Build a standardized result array.
     */
    private function makeResult(?int $localId, string $status, ?string $serverRef = null, ?string $error = null): array
    {
        $r = ['local_id' => $localId, 'status' => $status, 'server_ref' => $serverRef];
        if ($error !== null) $r['error'] = $error;
        return $r;
    }

    /**
     * Attempt AES-256-GCM decryption; return null on failure.
     */
    private function tryDecrypt(string $encryptedB64, string $aesKeyHex): ?string
    {
        try {
            return $this->decryptSmsPayload($encryptedB64, $aesKeyHex);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Store record for failed decryption (admin review).
     */
    private function storeFailedDecryption(string $deviceUuid, int $brandId, ?int $localId, string $sender, string $receivedAt, string $encrypted): string
    {
        return (string) $this->dataRepo->create([
            'device_id'        => $deviceUuid,
            'merchant_id'      => $brandId,
            'local_id'         => $localId,
            'sender'           => $sender,
            'received_at'      => $receivedAt,
            'encrypted_raw'    => $encrypted,
            'body'             => null,
            'parsed_type'      => 'unknown',
            'parser_type'      => 'unparsed',
            'parse_confidence' => 'low',
            'match_status'     => 'parse_error',
        ]);
    }

    /**
     * Run 2-tier parse: exact sender match → regex templates.
     * If sender not whitelisted (no template matches), returns null (no parse attempt).
     * Heuristic fallback only runs if a matching template exists but regex fails.
     */
    private function attemptParse(string $rawMessage, string $sender, int $brandId): ?array
    {
        // Gate 1: Find templates whose sender_pattern matches EXACTLY (case-sensitive)
        $templates = $this->templateRepo->findBySender($sender, $brandId);
        $templates  = $this->events->applyFilter('mfs.templates', $templates);

        // Sender not in whitelist → reject silently (store as admin_review)
        if (empty($templates)) {
            return null;
        }

        // Gate 2: Try regex templates (Tier 1)
        $parsed = $this->regexParser->parse($rawMessage, $templates);

        // Tier 2: Heuristic fallback — only if sender matched but regex failed
        return $parsed ?? $this->heuristicParser->parse($rawMessage);
    }

    /**
     * Build op_sms_parsed record from parsed result.
     */
    private function buildRecord(string $deviceUuid, int $brandId, ?int $localId, string $sender, string $receivedAt, string $encrypted, string $raw, ?array $parsed): array
    {
        return [
            'device_id'        => $deviceUuid,
            'merchant_id'      => $brandId,
            'local_id'         => $localId,
            'sender'           => $sender,
            'received_at'      => $receivedAt,
            'encrypted_raw'    => $encrypted,
            'body'             => $raw,
            'amount'           => $parsed['parsed_amount'] ?? null,
            'trx_id'           => $parsed['parsed_trx_id'] ?? null,
            'parsed_sender'    => $parsed['parsed_sender'] ?? null,
            'parsed_balance'   => $parsed['parsed_balance'] ?? null,
            'parsed_type'      => $parsed['parsed_type'] ?? 'unknown',
            'parser_type'      => $parsed['parse_method'] ?? 'unparsed',
            'template_id'      => $parsed['template_id'] ?? null,
            'parse_confidence' => $parsed['parse_confidence'] ?? 'low',
            'match_status'     => ($parsed === null) ? 'admin_review' : 'accepted',
        ];
    }

    /**
     * Queue mobile notification on successful parse.
     */
    private function notifyDevice(?array $parsed, string $deviceUuid, string $sender): void
    {
        if ($parsed === null || !/** @phpstan-ignore-next-line */ isset($parsed['parsed_amount'])) return;
        try {
            $this->notifService->queuePaymentNotification(
                $deviceUuid,
                $parsed['parsed_type'] ?? 'unknown',
                /** @phpstan-ignore nullCoalesce.offset */
                $parsed['parsed_amount'] ?? null,
                $parsed['parsed_sender'] ?? null,
                $parsed['parsed_trx_id'] ?? null,
                $sender,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Notification failed: ' . $e->getMessage());
        }
    }

    // ── Crypto Helpers ────────────────────────────────────────────

    /**
     * Decrypt an SMS payload encrypted with the device's AES-256 key.
     * Format: base64(IV(12) + ciphertext + auth_tag(16))
     */
    private function decryptSmsPayload(string $encryptedB64, string $aesKeyHex): string
    {
        $raw = base64_decode($encryptedB64, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid encrypted payload format.');
        }

        $key = hex2bin($aesKeyHex);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Invalid AES key.');
        }

        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, -16);
        $ciphertext = substr($raw, 12, strlen($raw) - 28);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('AES-256-GCM decryption failed.');
        }
        return $plaintext;
    }

    /**
     * Normalize ISO 8601 timestamp → MySQL DATETIME.
     */
    private function normalizeTimestamp(string $ts): string
    {
        try {
            return (new \DateTimeImmutable($ts))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return DateHelper::now();
        }
    }

    /**
     * Parse a single SMS message without storing.
     * Used by MfsService.
     *
     * @param string $rawMessage  The raw SMS body
     * @param string $sender      Sender identifier
     * @param int    $brandId     Merchant/brand ID
     * @return array|null Parsed data or null on failure
     */
    public function parse(string $rawMessage, string $sender, int $brandId): ?array
    {
        return $this->attemptParse($rawMessage, $sender, $brandId);
    }

    /**
     * Parse and store a single SMS.
     * Used by Mobile SmsController.
     */
    public function parseAndStore(string $deviceUuid, int $brandId, array $message): array
    {
        return $this->processBatch($deviceUuid, $brandId, [$message]);
    }
}
