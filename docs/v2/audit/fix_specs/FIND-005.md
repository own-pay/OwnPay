> **STATUS UPDATE (2026-06-11): IMPLEMENTED.** This specification was implemented in remediation pass 2. See `docs/v2/audit/fixes_applied.md` (FIX-005/007/008) and the updated finding status in `report_claude_fable_5.md`. Retained for historical traceability.

# FIND-005 — Outbound webhook SSRF via DNS-rebinding (TOCTOU)

Severity: MEDIUM
Status: SPEC_WRITTEN (requires connection-level IP pinning; behavior change to outbound HTTP)

## Problem (technical)
`WebhookDispatcher` validates the destination URL with `UrlValidator::isValidWebhookUrl()` (`src/Service/Notification/WebhookDispatcher.php:207,252`) — which resolves DNS and rejects private/reserved IPs — **but the subsequent cURL request (~L271) re-resolves the hostname independently**. An attacker controlling a DNS record can return a public IP at validation time and a private IP (e.g. `169.254.169.254`, `127.0.0.1`, `10.0.0.0/8`) microseconds later when cURL connects. This is a classic time-of-check/time-of-use (TOCTOU) DNS-rebinding bypass that can reach internal services.

`UrlValidator::isValidWebhookUrl()` itself is otherwise solid (HTTPS-only, IPv4+IPv6 private/reserved blocking via `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`).

## Recommended solution
Resolve the hostname **once**, validate the resolved IPs, then force cURL to connect to that exact validated IP using `CURLOPT_RESOLVE` (pinning), so check-time and use-time share the same address.

```php
// After isValidWebhookUrl($url) passes:
$parts = parse_url($url);
$host  = $parts['host'] ?? '';
$port  = $parts['port'] ?? 443;

$ips = [];
foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
    if (($r['type'] ?? '') === 'A'    && isset($r['ip']))   { $ips[] = $r['ip']; }
    if (($r['type'] ?? '') === 'AAAA' && isset($r['ipv6'])) { $ips[] = $r['ipv6']; }
}
if (!$ips && ($l = gethostbynamel($host))) { $ips = $l; }

$pinned = null;
foreach ($ips as $ip) {
    // reuse the same private/reserved guard used by UrlValidator
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        $pinned = $ip; break;
    }
}
if ($pinned === null) {
    // no public IP — refuse to send
    return /* failed delivery */;
}
curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$pinned}"]);
```

Expose the private/reserved check as a small public helper on `UrlValidator` (e.g. `UrlValidator::isPublicIp(string $ip): bool`) so the dispatcher and validator share one implementation.

## Files to change
- `src/Service/Notification/WebhookDispatcher.php` — pin the resolved IP before each cURL send (the two call sites guarded at L207/L252).
- `src/Security/UrlValidator.php` — add a public `isPublicIp()` helper (refactor of existing private `isPrivateIp()`).

## Why not a blind single-file patch
TLS SNI/cert validation must still match the original hostname (cURL handles this correctly with `CURLOPT_RESOLVE` since the Host/SNI stays the hostname while the connection IP is pinned). This needs a focused test against multi-A-record/CDN hosts to ensure legitimate webhooks still deliver, so it is specified rather than applied blindly.

## Verification
- Test: host resolving to a private IP → delivery refused.
- Test: host resolving to a public IP → delivers; connection uses the pinned IP.
- Test: rebinding simulation (validate public, then private) → blocked because the pinned IP is reused.
