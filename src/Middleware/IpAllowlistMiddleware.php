<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

/**
 * IpAllowlistMiddleware — per-key IP restriction.
 *
 * If an API key has `allowed_ips` set, only requests from those
 * IPs/CIDRs are permitted. Empty/null = all IPs allowed.
 *
 * Supports:
 *   - Exact IPv4/IPv6 match
 *   - CIDR notation (e.g. "192.168.1.0/24", "10.0.0.0/8")
 *   - Mixed lists
 */
final class IpAllowlistMiddleware
{
    /**
     * Validate that the current request IP is allowed.
     *
     * @param array|null $allowedIps List of allowed IPs/CIDRs, or null for unrestricted
     * @param string|null $clientIp  Override client IP (null = auto-detect)
     * @return array{allowed: bool, clientIp: string, reason: string}
     */
    public function validate(?array $allowedIps = null, ?string $clientIp = null): array
    {
        $ip = $clientIp ?? $this->detectClientIp();

        // No restriction configured — allow all
        if ($allowedIps === null || empty($allowedIps)) {
            return ['allowed' => true, 'clientIp' => $ip, 'reason' => ''];
        }

        foreach ($allowedIps as $entry) {
            $entry = trim($entry);
            if ($entry === '')
                continue;

            // CIDR match
            if (str_contains($entry, '/')) {
                if ($this->matchesCidr($ip, $entry)) {
                    return ['allowed' => true, 'clientIp' => $ip, 'reason' => ''];
                }
            } else {
                // Exact match
                if ($ip === $entry) {
                    return ['allowed' => true, 'clientIp' => $ip, 'reason' => ''];
                }
            }
        }

        return [
            'allowed' => false,
            'clientIp' => $ip,
            'reason' => "IP address {$ip} is not in the allowlist for this API key.",
        ];
    }

    /**
     * Enforce IP allowlist — sends 403 and exits if blocked.
     */
    public function enforce(?array $allowedIps = null): void
    {
        $result = $this->validate($allowedIps);

        if (!$result['allowed']) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'code' => 'IP_NOT_ALLOWED',
                    'message' => $result['reason'],
                ],
            ]);
            exit;
        }
    }

    /**
     * Check if an IP matches a CIDR block.
     */
    private function matchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        // IPv4
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            // IPv6 or invalid — try inet_pton
            return $this->matchesCidrIpv6($ip, $subnet, $bits);
        }

        if ($bits === 0) {
            return true; // /0 matches everything
        }

        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * IPv6 CIDR matching using inet_pton.
     */
    private function matchesCidrIpv6(string $ip, string $subnet, int $bits): bool
    {
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);

        if ($ipBin === false || $subBin === false) {
            return false;
        }

        // Compare bit-by-bit
        $ipHex = bin2hex($ipBin);
        $subHex = bin2hex($subBin);

        // Full hex digit comparison
        $fullDigits = intdiv($bits, 4);
        if (substr($ipHex, 0, $fullDigits) !== substr($subHex, 0, $fullDigits)) {
            return false;
        }

        // Remaining bits
        $remainBits = $bits % 4;
        if ($remainBits > 0 && $fullDigits < strlen($ipHex)) {
            $ipNibble = intval($ipHex[$fullDigits], 16);
            $subNibble = intval($subHex[$fullDigits], 16);
            $mask = (0xF << (4 - $remainBits)) & 0xF;
            if (($ipNibble & $mask) !== ($subNibble & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect the client's real IP.
     */
    private function detectClientIp(): string
    {
        // Trust X-Forwarded-For only behind known reverse proxies
        // For production, configure trusted proxies explicitly
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
