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

    public function startUpdate(string $version): int
    {
        $current = $this->latest()['to_version'] ?? '0.0.0';
        return (int) $this->create([
            'from_version' => $current,
            'to_version'   => $version,
            'status'       => 'started',
        ]);
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

    public function updateStep(int $id, string $status, ?string $error = null): void
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
    public function markFailed(int $id, string $error): void
    {
        $this->updateStep($id, 'failed', $error);
    }

    public function completeUpdate(int $id): void
    {
        $this->updateStep($id, 'completed');
    }

    public function markRolledBack(int $id, string $error = null): void
    {
        $this->updateStep($id, 'rolled_back', $error);
    }

    /**
     * List finished updates (admin page).
     */
    public function listFinished(int $limit = 10, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status IN ('completed','failed','rolled_back') ORDER BY id DESC LIMIT :lim OFFSET :off",
            ['lim' => $limit, 'off' => $offset]
        );
    }

    /**
     * Count finished updates.
     */
    public function countFinished(): int
    {
        return $this->db->count($this->table, "status IN ('completed','failed','rolled_back')");
    }
}
