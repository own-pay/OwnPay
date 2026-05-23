# Findings: security-review-remediations

## Vulnerability 1: IPv6 SSRF Bypass in `UrlValidator` / `HttpClient`
- **Location:** `src/Security/UrlValidator.php` (`isValidWebhookUrl`) & `src/Service/System/HttpClient.php` (`request`)
- **Analysis:**
  `isValidWebhookUrl` resolves the host using `gethostbynamel()`, which is IPv4-only. Under an IPv6-supported server environment, a hostname resolving to both public IPv4 and private IPv6 loopback (e.g., `::1`) can bypass checks.
- **Remediation:**
  Set `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4` on the cURL resource in `HttpClient.php`.

## Vulnerability 2: Sensitive Header Leakage on Cross-Origin Redirects
- **Location:** `src/Service/System/HttpClient.php` (`request`)
- **Analysis:**
  `HttpClient` follows redirect locations manually in a `while (true)` loop. The headers array is passed unmodified to subsequent redirect targets. If an outbound webhook or request has `Authorization` headers, and redirects to a third-party host, the headers leak.
- **Remediation:**
  Compare the host of the initial URL with the redirect URL host. If they differ, unset `Authorization`, `Cookie`, and `X-Api-Key` headers.

## Vulnerability 3: Callback Sandbox Escape in `PluginSandbox`
- **Location:** `src/Plugin/PluginSandbox.php` (`isDangerousFunction`)
- **Analysis:**
  `PluginSandbox` blocks `array_map`, `array_filter`, etc., to avoid sandbox escape via callbacks. However, PHP has other callback-accepting functions:
  - `array_uintersect`, `array_uintersect_assoc`, `array_uintersect_uassoc`
  - `array_udiff`, `array_udiff_assoc`, `array_udiff_uassoc`
  - `array_diff_ukey`, `array_diff_uassoc`, `array_intersect_ukey`
  - `preg_replace_callback`, `preg_replace_callback_array`
- **Remediation:**
  Add these functions to the `$dangerous` blocklist in `PluginSandbox::isDangerousFunction()`.
