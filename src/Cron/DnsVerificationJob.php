<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Domain\DnsVerifier;
use OwnPay\Support\DateHelper;

/**
 * DNS verification job — re-checks pending domains every 6 hours.
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
     * Run verification for all pending domains.
     * @return array{verified: int, failed: int}
     */
    public function run(): array
    {
        $pending = $this->domains->findPendingVerification();

        $verified = 0;
        $failed = 0;

        foreach ($pending as $domain) {
            $result = $this->verifier->verifyTxt(
                $domain['domain'],
                $domain['verification_token']
            );

            if ($result) {
                $this->domains->update((int) $domain['id'], [
                    'dns_verified'    => 1,
                    'status'          => 'active',
                    'dns_verified_at' => DateHelper::now(),
                ]);
                $verified++;
            } else {
                $failed++;
                // Auto-remove after 7 days unverified
                $createdAt = (new \DateTimeImmutable($domain['created_at']))->getTimestamp();
                /** @phpstan-ignore-next-line */
                if ($createdAt !== false && (time() - $createdAt) > 604800) {
                    $this->domains->delete((int) $domain['id']);
                }
            }
        }

        return ['verified' => $verified, 'failed' => $failed];
    }
}
