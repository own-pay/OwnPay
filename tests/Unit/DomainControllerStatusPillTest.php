<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Controller\Admin\DomainController;
use PHPUnit\Framework\TestCase;

final class DomainControllerStatusPillTest extends TestCase
{
    public function testInactiveWinsRegardlessOfOtherFields(): void
    {
        $pill = DomainController::computeStatusPill([
            'status' => 'inactive', 'dns_verified' => 1, 'ssl_status' => 'active',
        ]);
        $this->assertSame(['label' => 'Inactive', 'class' => 'op-badge-danger'], $pill);
    }

    public function testPendingDnsWhenNotVerified(): void
    {
        $pill = DomainController::computeStatusPill([
            'status' => 'pending', 'dns_verified' => 0, 'ssl_status' => 'none',
        ]);
        $this->assertSame(['label' => 'Pending DNS', 'class' => 'op-badge-warning'], $pill);
    }

    public function testPendingDnsWhenStatusPendingEvenIfDnsVerifiedFlagIsStale(): void
    {
        $pill = DomainController::computeStatusPill([
            'status' => 'pending', 'dns_verified' => 1, 'ssl_status' => 'active',
        ]);
        $this->assertSame(['label' => 'Pending DNS', 'class' => 'op-badge-warning'], $pill);
    }

    public function testSslIssueWhenVerifiedButSslNotActive(): void
    {
        $pill = DomainController::computeStatusPill([
            'status' => 'active', 'dns_verified' => 1, 'ssl_status' => 'expired',
        ]);
        $this->assertSame(['label' => 'SSL Issue', 'class' => 'op-badge-warning'], $pill);
    }

    public function testActiveWhenEverythingHealthy(): void
    {
        $pill = DomainController::computeStatusPill([
            'status' => 'active', 'dns_verified' => 1, 'ssl_status' => 'active',
        ]);
        $this->assertSame(['label' => 'Active', 'class' => 'op-badge-success'], $pill);
    }

    public function testMissingKeysDefaultToPendingDns(): void
    {
        $pill = DomainController::computeStatusPill([]);
        $this->assertSame(['label' => 'Pending DNS', 'class' => 'op-badge-warning'], $pill);
    }
}
