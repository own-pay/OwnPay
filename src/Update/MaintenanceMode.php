<?php
declare(strict_types=1);

namespace OwnPay\Update;

/**
 * Maintenance mode — file-based lock for update/maintenance windows.
 */
final class MaintenanceMode
{
    private string $lockFile;

    public function __construct(?string $lockFile = null)
    {
        $this->lockFile = $lockFile ?? dirname(__DIR__, 2) . '/storage/.maintenance';
    }

    public function enter(string $reason = 'System maintenance'): void
    {
        $data = [
            'reason'     => $reason,
            'started_at' => date('Y-m-d H:i:s'),
            'retry_after' => 300,
        ];
        file_put_contents($this->lockFile, json_encode($data));
    }

    public function exit(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    public function isActive(): bool
    {
        return file_exists($this->lockFile);
    }

    public function info(): ?array
    {
        if (!$this->isActive()) {
            return null;
        }
        $data = json_decode(file_get_contents($this->lockFile) ?: '{}', true);
        return is_array($data) ? $data : null;
    }
}
