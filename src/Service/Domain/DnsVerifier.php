<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

/**
 * DNS verifier — checks TXT and A/CNAME records for domain verification.
 */
final class DnsVerifier
{
    /**
     * Verify TXT record for domain ownership.
     */
    public function verifyTxt(string $domain, string $expectedValue): bool
    {
        $recordName = '_ownpay-verification.' . $domain;

        $records = @dns_get_record($recordName, DNS_TXT);
        if ($records === false || empty($records)) {
            return false;
        }

        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if ($txt === $expectedValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify A record points to our server.
     */
    public function verifyARecord(string $domain, string $expectedIp): bool
    {
        $records = @dns_get_record($domain, DNS_A);
        if ($records === false || empty($records)) {
            return false;
        }

        foreach ($records as $record) {
            if (($record['ip'] ?? '') === $expectedIp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify CNAME record.
     */
    public function verifyCname(string $domain, string $expectedTarget): bool
    {
        $records = @dns_get_record($domain, DNS_CNAME);
        if ($records === false || empty($records)) {
            return false;
        }

        foreach ($records as $record) {
            $target = rtrim($record['target'] ?? '', '.');
            if (strtolower($target) === strtolower($expectedTarget)) {
                return true;
            }
        }

        return false;
    }
}
