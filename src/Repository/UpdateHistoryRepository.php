<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository class responsible for database operations, persistence, and audit logging
 * of system self-update operations within the 'op_update_history' table.
 *
 * This repository is globally scoped (not merchant-scoped) and tracks the lifecycle
 * of system updates, recording version transitions, statuses, backups, and failures.
 */
class UpdateHistoryRepository extends BaseRepository
{
    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_update_history';

    /**
     * The list of columns that are safe to be bulk-filled on insertion or update.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'from_version', 'to_version', 'status', 'backup_path',
        'checksum', 'error', 'completed_at',
    ];

    /**
     * Retrieves the latest update history record.
     *
     * @return array<string, mixed>|null The latest update record or null if none exist.
     */
    public function latest(): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Initializes a new system update process.
     *
     * @param string $version The target version string being applied.
     * @return int The primary key identifier of the newly started update process.
     */
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
     * Checks if a system update process is currently in progress.
     *
     * Scanning for statuses like 'started', 'backup_created', 'downloaded', 'applied', or 'verified'.
     *
     * @return bool True if an update is actively in progress, false otherwise.
     */
    public function isUpdateInProgress(): bool
    {
        return $this->db->exists(
            $this->table,
            "status IN ('started','backup_created','downloaded','applied','verified')"
        );
    }

    /**
     * Transitions an ongoing update process to a new step or status.
     *
     * Automatically registers the completion timestamp if a final terminal state is reached.
     *
     * @param int $id The internal primary identifier of the update record.
     * @param string $status The new status state to apply.
     * @param string|null $error Optional error details if the step encountered an exception.
     * @return void
     */
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

    /**
     * Marks a specific update process as failed, logging the error explanation.
     *
     * @param int $id The internal primary identifier of the update record.
     * @param string $error The failure details or traceback details.
     * @return void
     */
    public function markFailed(int $id, string $error): void
    {
        $this->updateStep($id, 'failed', $error);
    }

    /**
     * Marks a specific update process as successfully completed.
     *
     * @param int $id The internal primary identifier of the update record.
     * @return void
     */
    public function completeUpdate(int $id): void
    {
        $this->updateStep($id, 'completed');
    }

    /**
     * Marks a specific update process as rolled back, optionally detailing the trigger error.
     *
     * @param int $id The internal primary identifier of the update record.
     * @param string|null $error Optional failure description causing the rollback.
     * @return void
     */
    public function markRolledBack(int $id, string $error = null): void
    {
        $this->updateStep($id, 'rolled_back', $error);
    }

    /**
     * Retrieves a paginated list of updates that have reached a terminal status.
     * Used for system administration updates history display.
     *
     * @param int $limit Maximum number of update records to retrieve. Defaults to 10.
     * @param int $offset Numerical offset for database pagination. Defaults to 0.
     * @return array<int, array<string, mixed>> List of matching terminal updates.
     */
    public function listFinished(int $limit = 10, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT *, TIMESTAMPDIFF(SECOND, started_at, completed_at) AS duration FROM {$this->table} WHERE status IN ('completed','failed','rolled_back') ORDER BY id DESC LIMIT :lim OFFSET :off",
            ['lim' => $limit, 'off' => $offset]
        );
    }

    /**
     * Retrieves the active update in progress, if any.
     *
     * @return array<string, mixed>|null The active update or null if none is in progress.
     */
    public function getActiveUpdate(): ?array
    {
        return $this->db->fetchOne(
            "SELECT *, TIMESTAMPDIFF(SECOND, started_at, NOW(6)) AS duration FROM {$this->table} WHERE status IN ('started','backup_created','downloaded','applied','verified') ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Counts the total number of finished update attempts in history.
     *
     * @return int The total count of completed, failed, or rolled back update runs.
     */
    public function countFinished(): int
    {
        return $this->db->count($this->table, "status IN ('completed','failed','rolled_back')");
    }
}
