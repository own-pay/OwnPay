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
        $names = [
            '_ownpay-verify.' . $domain,
            '_ownpay-verification.' . $domain
        ];

        foreach ($names as $recordName) {
            // 1. Native dns_get_record lookup
            $records = @dns_get_record($recordName, DNS_TXT);
            if (is_array($records) && !empty($records)) {
                foreach ($records as $record) {
                    $txt = trim($record['txt'] ?? '');
                    if ($txt === $expectedValue ||
                        $txt === 'ownpay-verify=' . $expectedValue ||
                        $txt === 'ownpay-verification=' . $expectedValue
                    ) {
                        return true;
                    }
                }
            }

            // 2. DNS-over-HTTPS (DoH) fallback via Cloudflare
            try {
                $url = 'https://cloudflare-dns.com/dns-query?name=' . urlencode($recordName) . '&type=TXT';
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dns-json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $resp = curl_exec($ch);
                curl_close($ch);

                if (is_string($resp) && $resp !== '') {
                    $data = json_decode($resp, true);
                    if (is_array($data) && isset($data['Answer']) && is_array($data['Answer'])) {
                        foreach ($data['Answer'] as $ans) {
                            if (is_array($ans)) {
                                $ansTypeVal = $ans['type'] ?? 0;
                                $ansType = is_int($ansTypeVal) || is_string($ansTypeVal) ? (int) $ansTypeVal : 0;
                                if ($ansType === 16) {
                                    $ansDataVal = $ans['data'] ?? '';
                                    $ansData = is_string($ansDataVal) ? $ansDataVal : '';
                                    $txt = trim($ansData, " \t\n\r\0\x0B\"");
                                    if ($txt === $expectedValue ||
                                        $txt === 'ownpay-verify=' . $expectedValue ||
                                        $txt === 'ownpay-verification=' . $expectedValue
                                    ) {
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore transient network errors/timeouts
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
