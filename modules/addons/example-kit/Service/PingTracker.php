<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\ExampleKit\Service;

/**
 * Tiny collaborator shipped in a separate file to demonstrate multi-file plugin autoloading
 * (resolved as OwnPay\Modules\Addons\ExampleKit\Service\PingTracker → Service/PingTracker.php).
 *
 * Counts how many completed-payment events the plugin has observed during the current process.
 */
final class PingTracker
{
    private int $count = 0;

    public function record(): void
    {
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }
}
