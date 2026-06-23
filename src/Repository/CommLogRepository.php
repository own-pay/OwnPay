<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for communication logs (`op_comm_log` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Logs outbound emails, SMS notifications, and webhook requests.
 */
final class CommLogRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_comm_log';
    protected array $fillable = [
        'merchant_id', 'channel', 'recipient', 'subject', 'body',
        'provider', 'status', 'error', 'sent_at',
    ];

    /**
     * Logs an outbound communication attempt.
     *
     * @param int|null $merchantId Associated merchant identifier, or null if system-wide.
     * @param string $channel The message dispatch channel (e.g., 'sms', 'email', 'webhook').
     * @param string $recipient The target recipient address or phone number.
     * @param string|null $subject The message subject line (if applicable).
     * @param string|null $body The message content payload.
     * @param string|null $provider The dynamic provider gateway used to send the message.
     * @param string $status The initial log record status (default is 'queued').
     * @return string Last inserted primary key ID of the log record.
     */
    public function log(
        ?int $merchantId,
        string $channel,
        string $recipient,
        ?string $subject = null,
        ?string $body = null,
        ?string $provider = null,
        string $status = 'queued'
    ): string {
        return $this->create([
            'merchant_id' => $merchantId,
            'channel'     => $channel,
            'recipient'   => $recipient,
            'subject'     => $subject,
            'body'        => $body,
            'provider'    => $provider,
            'status'      => $status,
        ]);
    }

    /**
     * Marks a communication log record as successfully sent.
     *
     * Uses microsecond-precision timestamps.
     *
     * @param int $id The primary key identifier of the log record.
     * @return void
     */
    public function markSent(int $id): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET status = 'sent', sent_at = NOW(6) WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Marks a communication log record as failed and records the error description.
     *
     * @param int $id The primary key identifier of the log record.
     * @param string $error The failure description or exception trace message.
     * @return void
     */
    public function markFailed(int $id, string $error): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET status = 'failed', error = :err WHERE id = :id",
            ['err' => mb_substr($error, 0, 500), 'id' => $id]
        );
    }

    /**
     * Lists SMS queue log entries for a specific merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of matching communication log records.
     */
    public function listSmsQueue(int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE channel = 'sms' AND merchant_id = :mid ORDER BY created_at DESC LIMIT :lim",
            ['mid' => $merchantId, 'lim' => $limit]
        );
    }

    /**
     * Retrieves aggregated SMS queue status counts (queued, sent, failed) for a merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return array{queued: int, sent: int, failed: int} Aggregated status counts.
     */
    public function getSmsQueueStats(int $merchantId): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                COUNT(CASE WHEN status='queued' THEN 1 END) as queued,
                COUNT(CASE WHEN status='sent' THEN 1 END) as sent,
                COUNT(CASE WHEN status='failed' THEN 1 END) as failed
             FROM {$this->table} WHERE channel='sms' AND merchant_id = :mid",
            ['mid' => $merchantId]
        );
        $queuedVal = $row['queued'] ?? 0;
        $sentVal = $row['sent'] ?? 0;
        $failedVal = $row['failed'] ?? 0;
        return [
            'queued' => is_scalar($queuedVal) ? (int) $queuedVal : 0,
            'sent'   => is_scalar($sentVal) ? (int) $sentVal : 0,
            'failed' => is_scalar($failedVal) ? (int) $failedVal : 0,
        ];
    }

    /**
     * Lists pending SMS log entries ready to be dispatched by a companion mobile device.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to return.
     * @return array<int, array{id: int, to: string, body: string}> List of queued SMS records.
     */
    public function listPendingSms(int $merchantId, int $limit = 20): array
    {
        /** @var array<int, array{id: int, to: string, body: string}> $rows */
        $rows = $this->db->fetchAll(
            "SELECT id, recipient as `to`, body FROM {$this->table}
             WHERE channel = 'sms' AND status = 'queued' AND merchant_id = :mid
             ORDER BY created_at ASC LIMIT :lim",
            ['mid' => $merchantId, 'lim' => $limit]
        );
        return $rows;
    }

    /**
     * Resets a FAILED SMS communication log entry back to queued status for retry.
     *
     * The `status = 'failed'` guard makes retry idempotent: re-issuing a retry on a
     * row that is already 'queued', 'sending', or 'sent' is a no-op (0 rows), so a
     * duplicate request can never re-deliver an already-sent SMS.
     *
     * @param int $id The primary key identifier of the log record.
     * @param int $merchantId Active brand/store identifier context.
     * @return int Number of rows actually requeued (0 if not found, not owned, or not in 'failed' state).
     */
    public function retrySms(int $id, int $merchantId): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'queued', error = NULL WHERE id = :id AND merchant_id = :mid AND channel = 'sms' AND status = 'failed'",
            ['id' => $id, 'mid' => $merchantId]
        );
    }

    /**
     * Lists email log entries for a specific merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of matching communication log records.
     */
    public function listEmailQueue(int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE channel = 'email' AND merchant_id = :mid ORDER BY created_at DESC LIMIT :lim",
            ['mid' => $merchantId, 'lim' => $limit]
        );
    }

    /**
     * Lists Telegram log entries for a specific merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of matching communication log records.
     */
    public function listTelegramQueue(int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE channel = 'telegram' AND merchant_id = :mid ORDER BY created_at DESC LIMIT :lim",
            ['mid' => $merchantId, 'lim' => $limit]
        );
    }

    /**
     * Lists Webhook log entries for a specific merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of matching communication log records.
     */
    public function listWebhookQueue(int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE channel = 'webhook' AND merchant_id = :mid ORDER BY created_at DESC LIMIT :lim",
            ['mid' => $merchantId, 'lim' => $limit]
        );
    }
}

