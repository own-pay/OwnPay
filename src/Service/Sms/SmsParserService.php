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
 * Orchestrator for the two-tier SMS parsing engine.
 *
 * Coordinates message ingestion and processing lifecycles:
 * 1. Resolves paired companion device encryption credentials.
 * 2. Performs AES-256-GCM payload decryption.
 * 3. Identifies and filters duplicates within a temporal deduplication window.
 * 4. Dispatches the decrypted payload through matching regex templates.
 * 5. Falls back to lexical proximity heuristics.
 * 6. Commits parsed metadata records to persistent database tables.
 */
final class SmsParserService
{
    /**
     * Paired device repository.
     *
     * @var PairedDeviceRepository
     */
    private $deviceRepo;

    /**
     * SMS templates repository.
     *
     * @var SmsTemplateRepository
     */
    private $templateRepo;

    /**
     * SMS logs repository.
     *
     * @var SmsDataRepository
     */
    private $dataRepo;

    /**
     * Regex matching parsing engine.
     *
     * @var SmsRegexParser
     */
    private $regexParser;

    /**
     * Lexical fallback parsing engine.
     *
     * @var SmsHeuristicParser
     */
    private $heuristicParser;

    /**
     * Field encryption utility.
     *
     * @var FieldEncryptor
     */
    private $encryptor;

    /**
     * Notification dispatch helper.
     *
     * @var MobileNotificationService
     */
    private $notifService;

    /**
     * System event manager.
     *
     * @var EventManager
     */
    private $events;

    /**
     * System logger service.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Initializes the parsing orchestrator.
     *
     * Constructor signatures remain untyped to facilitate test double and stub injection.
     *
     * @param PairedDeviceRepository $deviceRepo Paired device repository.
     * @param SmsTemplateRepository $templateRepo SMS template configuration repository.
     * @param SmsDataRepository $dataRepo Parsed SMS logs database repository.
     * @param SmsRegexParser $regexParser Regex matching parsing engine.
     * @param SmsHeuristicParser $heuristicParser Lexical heuristics fallback parser.
     * @param FieldEncryptor $encryptor Cryptographic storage field encryptor.
     * @param MobileNotificationService $notifService Mobile push notification system service.
     * @param EventManager|null $events The central system event and filter hooks manager.
     * @param Logger|null $logger The system logging service.
     */
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
     * Processes a batch of SMS messages emitted from a verified paired mobile device.
     *
     * Decrypts, normalizes, dedups, and parses each element in the batch sequence.
     *
     * @param string $deviceUuid The companion mobile device identifier.
     * @param int $brandId The unique brand/merchant identity context.
     * @param array<int, array<string, mixed>> $messages List of encrypted carrier SMS payload dictionaries.
     * @return array<int, array{local_id: int|null, status: string, server_ref?: string|null, error?: string}> Batch processing outcomes.
     */
    public function processBatch(string $deviceUuid, int $brandId, array $messages): array
    {
        $device = $this->deviceRepo->forTenant($brandId)->findByUuid($deviceUuid);
        if ($device === null) {
            return $this->rejectAll($messages, 'DEVICE_NOT_FOUND');
        }

        try {
            $aesKeyEncVal = $device['aes_key_encrypted'] ?? '';
            $aesKeyEnc = is_scalar($aesKeyEncVal) ? (string) $aesKeyEncVal : '';
            $deviceAesKey = $this->encryptor->decrypt($aesKeyEnc);
        } catch (\Throwable) {
            return $this->rejectAll($messages, 'KEY_DECRYPTION_FAILED');
        }

        $this->dataRepo = $this->dataRepo->forTenant($brandId);

        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->processOne($deviceUuid, $brandId, $deviceAesKey, $msg);
        }
        return $results;
    }

    /**
     * Processes a single incoming SMS message sequence.
     *
     * Runs authentication, duplicate validation, decryption, parsing, and notifications.
     *
     * @param string $deviceUuid Companion mobile device identifier.
     * @param int $brandId Unique brand/merchant identity context.
     * @param string $aesKeyHex Decoded hex secret key for AES-256 payload decryption.
     * @param array<string, mixed> $msg Encrypted carrier message dictionary variables.
     * @return array{local_id: int|null, status: string, server_ref?: string|null, error?: string} Individual process outcome.
     */
    private function processOne(string $deviceUuid, int $brandId, string $aesKeyHex, array $msg): array
    {
        $localIdVal = $msg['local_id'] ?? null;
        $localId    = is_scalar($localIdVal) ? (int) $localIdVal : null;
        $encryptedVal = $msg['encrypted_payload'] ?? '';
        $encrypted  = is_scalar($encryptedVal) ? (string) $encryptedVal : '';
        $senderVal  = $msg['sender'] ?? '';
        $sender     = trim(is_scalar($senderVal) ? (string)$senderVal : '');
        $receivedAtVal = $msg['received_at'] ?? DateHelper::now();
        $receivedAt = $this->normalizeTimestamp(is_scalar($receivedAtVal) ? (string)$receivedAtVal : DateHelper::now());

        if ($encrypted === '' || $sender === '') {
            return $this->makeResult($localId, 'rejected', null, 'MISSING_FIELDS');
        }

        if ($this->dataRepo->isDuplicate($deviceUuid, $sender, $receivedAt)) {
            return $this->makeResult($localId, 'duplicate');
        }

        $rawMessage = $this->tryDecrypt($encrypted, $aesKeyHex);
        if ($rawMessage === null) {
            $id = $this->storeFailedDecryption($deviceUuid, $brandId, $localId, $sender, $receivedAt, $encrypted);
            return $this->makeResult($localId, 'accepted', 'sms_' . $id, 'DECRYPTION_FAILED');
        }

        $parsed = $this->attemptParse($rawMessage, $sender, $brandId);

        $record = $this->buildRecord($deviceUuid, $brandId, $localId, $sender, $receivedAt, $encrypted, $rawMessage, $parsed);
        $id = $this->dataRepo->create($record);

        $this->notifyDevice($parsed, $deviceUuid, $sender);

        return $this->makeResult($localId, 'accepted', 'sms_' . $id);
    }

    /**
     * Rejects all messages in a batch with a consistent processing error flag.
     *
     * @param array<int, array<string, mixed>> $messages Input message list.
     * @param string $error Standard error label.
     * @return array<int, array{local_id: int|null, status: string, server_ref: string|null, error?: string}> List of rejection maps.
     */
    private function rejectAll(array $messages, string $error): array
    {
        return array_map(function($m) use ($error) {
            $lidVal = $m['local_id'] ?? null;
            $lid = is_scalar($lidVal) ? (int)$lidVal : null;
            return $this->makeResult($lid, 'rejected', null, $error);
        }, $messages);
    }

    /**
     * Constructs a structured API status response mapping.
     *
     * @param int|null $localId Client-side local sequence database key.
     * @param string $status Ingestion outcome string.
     * @param string|null $serverRef Generated server-side unique log reference.
     * @param string|null $error Error code reason metadata, if rejected.
     * @return array{local_id: int|null, status: string, server_ref: string|null, error?: string} Outgoing response mapping.
     */
    private function makeResult(?int $localId, string $status, ?string $serverRef = null, ?string $error = null): array
    {
        $r = ['local_id' => $localId, 'status' => $status, 'server_ref' => $serverRef];
        if ($error !== null) {
            $r['error'] = $error;
        }
        return $r;
    }

    /**
     * Attempts AES-256-GCM message body decryption.
     *
     * Catches and suppresses errors, returning null if keys or tags fail authentication.
     *
     * @param string $encryptedB64 Base64-encoded envelope package (IV + ciphertext + authentication tag).
     * @param string $aesKeyHex Hexadecimal representation of the device key.
     * @return string|null Plaintext message content string, or null on error.
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
     * Persists a fallback record representing failed decryption events.
     *
     * Flags entries for superadmin validation and diagnostic intervention.
     *
     * @param string $deviceUuid Companion mobile device identifier.
     * @param int $brandId Brand/merchant context identifier.
     * @param int|null $localId Client-side message identifier.
     * @param string $sender Original carrier sender identity.
     * @param string $receivedAt Normalized transmission timestamp.
     * @param string $encrypted Raw cipher base64 payload.
     * @return string Generated database insertion unique sequence primary key.
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
     * Evaluates message content across the parsing tiers.
     *
     * Restricts parsing to whitelisted carrier numbers. Falls back to lexical analysis
     * only when matching templates exist but strict regex parser rules fail.
     *
     * @param string $rawMessage Decrypted plain text message body content.
     * @param string $sender Carrier identifier patterns.
     * @param int $brandId Brand/merchant context identifier.
     * @return array{
     *   parsed_amount: float|null,
     *   parsed_trx_id: string|null,
     *   parsed_sender: string|null,
     *   parsed_balance: float|null,
     *   parsed_type: string,
     *   parse_method: string,
     *   template_id: int|null,
     *   parse_confidence: string
     * }|null Parsed outcomes or null if not parsed.
     */
    private function attemptParse(string $rawMessage, string $sender, int $brandId): ?array
    {
        $templatesVal = $this->templateRepo->findBySender($sender, $brandId);
        $res = $this->events->applyFilter('mfs.templates', $templatesVal);
        if (!is_array($res)) {
            $res = [];
        }
        $templates = [];
        foreach ($res as $item) {
            if (is_array($item)) {
                $itemMap = [];
                foreach ($item as $k => $v) {
                    $itemMap[(string)$k] = $v;
                }
                $templates[] = $itemMap;
            }
        }

        if (empty($templates)) {
            return null;
        }

        $parsed = $this->regexParser->parse($rawMessage, $templates);

        return $parsed ?? $this->heuristicParser->parse($rawMessage);
    }

    /**
     * Constructs parsed database record arrays for op_sms_parsed table schema insertion.
     *
     * @param string $deviceUuid Companion mobile device identifier.
     * @param int $brandId Brand/merchant context identifier.
     * @param int|null $localId Client-side local sequence key.
     * @param string $sender Carrier sender pattern.
     * @param string $receivedAt MySQL format timestamp.
     * @param string $encrypted Raw envelope base64 string.
     * @param string $raw Plaintext message body.
     * @param array<string, mixed>|null $parsed Extracted parsing outcomes.
     * @return array<string, mixed> Prepared database entity payload map.
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
            'match_status'     => ($parsed === null) ? 'admin_review' : 'pending',
        ];
    }

    /**
     * Dispatches transactional companion device mobile notifications on parsing success.
     *
     * @param array<string, mixed>|null $parsed Parsed fields.
     * @param string $deviceUuid Companion mobile device identifier.
     * @param string $sender Carrier sender pattern.
     * @return void
     */
    private function notifyDevice(?array $parsed, string $deviceUuid, string $sender): void
    {
        if ($parsed === null || !isset($parsed['parsed_amount'])) {
            return;
        }
        try {
            $pTypeVal = $parsed['parsed_type'] ?? 'unknown';
            $pType = is_scalar($pTypeVal) ? (string) $pTypeVal : 'unknown';
            $pAmt = $parsed['parsed_amount'];
            $amountVal = (is_float($pAmt) || is_int($pAmt) || is_string($pAmt)) ? $pAmt : null;
            $pSenderVal = $parsed['parsed_sender'] ?? null;
            $pSender = is_scalar($pSenderVal) ? (string) $pSenderVal : null;
            $pTrxIdVal = $parsed['parsed_trx_id'] ?? null;
            $pTrxId = is_scalar($pTrxIdVal) ? (string) $pTrxIdVal : null;

            $this->notifService->queuePaymentNotification(
                $deviceUuid,
                $pType,
                $amountVal,
                $pSender,
                $pTrxId,
                $sender,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Decrypts an SMS payload utilizing AES-256-GCM algorithm structure.
     *
     * Matches companion device mobile specifications (IV(12) + ciphertext + auth_tag(16)).
     *
     * @param string $encryptedB64 Base64 encoded payload.
     * @param string $aesKeyHex Hexadecimal representation of the secret key.
     * @return string Plaintext output.
     * @throws \RuntimeException If formatting, key sizes, or authentication verification tag validation fails.
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
     * Normalizes dynamic date representations to MySQL compatible string timestamps.
     *
     * @param string $ts Raw client date representation string.
     * @return string Normalized MySQL DATETIME representation string.
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
     * Parses a raw SMS string message dynamically without creating database records.
     *
     * Used for real-time, stateless SMS verification (no persistence side effects).
     *
     * @param string $rawMessage The raw SMS text body.
     * @param string $sender Original carrier sender identity pattern.
     * @param int $brandId Brand/merchant context identifier.
     * @return array<string, mixed>|null Parsed parameters, or null.
     */
    public function parse(string $rawMessage, string $sender, int $brandId): ?array
    {
        return $this->attemptParse($rawMessage, $sender, $brandId);
    }

    /**
     * Parses and persists a single incoming SMS request data frame.
     *
     * @param string $deviceUuid Companion mobile device identifier.
     * @param int $brandId Brand/merchant context identifier.
     * @param array<string, mixed> $message Encrypted message metadata properties.
     * @return array<int, array<string, mixed>> Batch execution statuses.
     */
    public function parseAndStore(string $deviceUuid, int $brandId, array $message): array
    {
        return $this->processBatch($deviceUuid, $brandId, [$message]);
    }
}
