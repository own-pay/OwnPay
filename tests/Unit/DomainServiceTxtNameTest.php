<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Domain\DnsVerifier;
use OwnPay\Service\Domain\DomainService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * NOTE: `DomainRepository` is a `final` class (confirmed via `ClassIsFinalException`
 * when attempting `createMock(DomainRepository::class)` directly), so it cannot be
 * doubled by PHPUnit. `Database` is not final, so a real `DomainRepository` is
 * constructed here wrapping a mocked `Database` - this exercises the repository's
 * real `forTenant()`/`createScoped()`/`findScoped()` logic while stubbing only the
 * actual DB boundary, keeping this a true unit test of `DomainService`'s string output.
 */
#[AllowMockObjectsWithoutExpectations]
final class DomainServiceTxtNameTest extends TestCase
{
    private function service(DomainRepository $repo): DomainService
    {
        return new DomainService($repo, new DnsVerifier(), EventManager::getInstance());
    }

    protected function tearDown(): void
    {
        EventManager::resetInstance();
        parent::tearDown();
    }

    public function testMapInstructionsUseOwnpayVerifyNotVerification(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturn(null); // findByDomain(): no existing domain
        $db->method('insert')->willReturn('1');
        $repo = new DomainRepository($db);

        $result = $this->service($repo)->map(5, 'pay.example.com');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('_ownpay-verify.pay.example.com', $result['instructions']);
        $this->assertStringNotContainsString('_ownpay-verification.', $result['instructions']);
    }

    public function testVerifyTxtNotFoundErrorUsesOwnpayVerifyNotVerification(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturn([
            'domain' => 'pay.example.com',
            'verification_token' => 'op-verify-doesnotexist',
        ]);
        $repo = new DomainRepository($db);

        $result = $this->service($repo)->verify(1, 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('_ownpay-verify.pay.example.com', $result['error']);
        $this->assertStringNotContainsString('_ownpay-verification.', $result['error']);
    }
}
