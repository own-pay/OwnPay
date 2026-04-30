<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class CommLogRepository extends BaseRepository
{
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
}
