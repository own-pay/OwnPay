<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Domain\DnsVerifier;
use OwnPay\Support\DateHelper;

/**
 * DNS verification job - re-checks pending domains every 6 hours.
 */
final class DnsVerificationJob
{
    private DomainRepository $domains;
    private DnsVerifier $verifier;

    public function __construct(DomainRepository $domains, DnsVerifier $verifier)
    {
        $this->domains = $domains;
        $this->verifier = $verifier;
    }

    /**
     * Run verification for pending domains and re-verification for stale active ones.
     * @return array{verified: int, failed: int, revoked: int}
     */
    public function run(): array
    {
        $pending = $this->domains->findPendingVerification();

        $verified = 0;
        $failed = 0;

        foreach ($pending as $domain) {
            if (!isset($domain['domain']) || !is_string($domain['domain']) ||
                !isset($domain['verification_token']) || !is_string($domain['verification_token']) ||
                !isset($domain['id']) || !is_scalar($domain['id']) ||
                !isset($domain['created_at']) || !is_string($domain['created_at'])) {
                continue;
            }

            $domainId = (int) $domain['id'];
            $result = $this->verifier->verifyTxt(
                $domain['domain'],
                $domain['verification_token']
            );

            if ($result) {
                $this->domains->update($domainId, [
                    'dns_verified'    => 1,
                    'status'          => 'active',
                    'dns_verified_at' => DateHelper::now(),
                ]);
                $verified++;
            } else {
                $failed++;
                // Auto-remove after 7 days unverified
                try {
                    $createdAt = (new \DateTimeImmutable($domain['created_at']))->getTimestamp();
                    if ((time() - $createdAt) > 604800) {
                        $this->domains->delete($domainId);
                    }
                } catch (\Exception $e) {
                    // Ignore malformed dates
                }
            }
        }

        $revoked = 0;
        $stale = $this->domains->findStaleVerified(24);
        foreach ($stale as $domain) {
            if (!isset($domain['domain'], $domain['verification_token'], $domain['id']) ||
                !is_string($domain['domain']) || !is_string($domain['verification_token']) || !is_scalar($domain['id'])) {
                continue;
            }
            $domainId = (int) $domain['id'];
            $stillValid = $this->verifier->verifyTxt($domain['domain'], $domain['verification_token']);
            if ($stillValid) {
                $this->domains->update($domainId, ['dns_verified_at' => DateHelper::now()]);
            } else {
                $this->domains->update($domainId, [
                    'dns_verified' => 0,
                    'status'       => 'pending',
                ]);
                $revoked++;
            }
        }

        return ['verified' => $verified, 'failed' => $failed, 'revoked' => $revoked];
    }
}
