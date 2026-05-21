<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class CommLogRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_comm_log';
    protected array $fillable = [
        'merchant_id', 'channel', 'recipient', 'subject', 'body',
        'provider', 'status', 'error', 'sent_at',
    ];

    /**
     * Log outbound communication (email, SMS, telegram, webhook).
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

    public function markSent(int $id): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET status = 'sent', sent_at = NOW(6) WHERE id = :id",
            ['id' => $id]
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET status = 'failed', error = :err WHERE id = :id",
            ['err' => mb_substr($error, 0, 500), 'id' => $id]
        );
    }

    /**
     * List SMS queue entries for merchant.
     */
    public function listSmsQueue(int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE channel = 'sms' AND merchant_id = :mid ORDER BY created_at DESC LIMIT {$limit}",
            ['mid' => $merchantId]
        );
    }

    /**
     * Get SMS queue stats for merchant.
     */
    public function getSmsQueueStats(int $merchantId): array
    {
        return $this->db->fetchOne(
            "SELECT
                COUNT(CASE WHEN status='queued' THEN 1 END) as queued,
                COUNT(CASE WHEN status='sent' THEN 1 END) as sent,
                COUNT(CASE WHEN status='failed' THEN 1 END) as failed
             FROM {$this->table} WHERE channel='sms' AND merchant_id = :mid",
            ['mid' => $merchantId]
        ) ?? ['queued' => 0, 'sent' => 0, 'failed' => 0];
    }

    /**
     * List pending SMS for mobile device to send.
     */
    public function listPendingSms(int $merchantId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT id, recipient as `to`, body FROM {$this->table}
             WHERE channel = 'sms' AND status = 'queued' AND merchant_id = :mid
             ORDER BY created_at ASC LIMIT {$limit}",
            ['mid' => $merchantId]
        );
    }

    /**
     * Reset SMS entry to queued for retry.
     * BUG-49 FIX: Removed non-existent 'attempt' column; use 'queued' not 'pending'.
     */
    public function retrySms(int $id, int $merchantId): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET status = 'queued', error = NULL WHERE id = :id AND merchant_id = :mid AND channel = 'sms'",
            ['id' => $id, 'mid' => $merchantId]
        );
    }
}
