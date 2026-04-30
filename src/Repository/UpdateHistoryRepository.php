<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class UpdateHistoryRepository extends BaseRepository
{
    protected string $table = 'op_update_history';
    protected array $fillable = [
        'from_version', 'to_version', 'status', 'backup_path',
        'checksum', 'error', 'completed_at',
    ];

    /**
     * Get latest update entry.
     */
    public function latest(): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Check if update in progress.
     */
    public function isUpdateInProgress(): bool
    {
        return $this->db->exists(
            $this->table,
            "status IN ('started','backup_created','downloaded','applied','verified')"
        );
    }

    public function markStatus(int $id, string $status, ?string $error = null): void
    {
        $params = ['status' => $status, 'id' => $id];
        $sql = "UPDATE {$this->table} SET status = :status";
        if ($error !== null) {
            $sql .= ", error = :err";
            $params['err'] = $error;
        }
        if ($status === 'completed' || $status === 'failed' || $status === 'rolled_back') {
            $sql .= ", completed_at = NOW(6)";
        }
        $sql .= " WHERE id = :id";
        $this->db->update($sql, $params);
    }
}
