<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Domain\DnsVerifier;
use OwnPay\Support\DateHelper;

/**
 * Class DnsVerificationJob
 *
 * Enterprise cron job executing DNS token-based TXT record verification for pending merchant custom domains.
 * Ensures the white-label custom domain pipeline resolves correctly, maintaining TLS and hosting validation
 * standards. Auto-deletes domains remaining unverified for more than 7 days.
 *
 * @package OwnPay\Cron
 */
final class DnsVerificationJob
{
    /**
     * @var DomainRepository Repository handling white-label merchant custom domains.
     */
    private DomainRepository $domains;

    /**
     * @var DnsVerifier Service verifying the existence of target DNS TXT records.
     */
    private DnsVerifier $verifier;

    /**
     * DnsVerificationJob constructor.
     *
     * @param DomainRepository $domains  Repository handling white-label merchant custom domains.
     * @param DnsVerifier      $verifier Service verifying the existence of target DNS TXT records.
     */
    public function __construct(DomainRepository $domains, DnsVerifier $verifier)
    {
        $this->domains = $domains;
        $this->verifier = $verifier;
    }

    /**
     * Runs verification for all pending custom domains.
     *
     * Queries domain records with status pending, checks validation TXT records, and updates
     * matching merchant custom domains to active status or prunes old expired entries.
     *
     * @return array{verified: int, failed: int} Results matrix of verified versus failed domain records.
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
                    'dns_verified' => 1,
                    'status'       => 'active',
                    'verified_at'  => DateHelper::now(),
                ]);
                $verified++;
            } else {
                $failed++;
                // Prune the pending domain entry if verification remains unresolved after the 7-day validation threshold.
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
