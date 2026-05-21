<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

/**
 * Verification service checking DNS TXT, A, and CNAME records.
 *
 * Used during custom domain verification flows to establish ownership and route alignment.
 */
final class DnsVerifier
{
    /**
     * Verifies the presence of a verification TXT record to prove domain ownership.
     *
     * @param string $domain The domain name to inspect.
     * @param string $expectedValue The expected TXT record value.
     * @return bool True if a matching TXT record is found; false otherwise.
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
     * Verifies that the domain's A record points to a target server IP address.
     *
     * @param string $domain The domain name to inspect.
     * @param string $expectedIp The expected target IPv4 address.
     * @return bool True if a matching A record is found; false otherwise.
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
     * Verifies that the domain's CNAME record points to an expected target host.
     *
     * @param string $domain The domain name to inspect.
     * @param string $expectedTarget The expected target host name.
     * @return bool True if the domain CNAME matches the expected target; false otherwise.
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
