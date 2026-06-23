# OwnPay Core - Pass Log

This document records high-risk components and vulnerabilities that were investigated during the adversarial audit but successfully withstood rigorous refutation attempts, proving the platform's compensating controls.

## 1. Webhook Signature Spoofing (Gateway & Internal)
- **Investigation**: Can an attacker forge a webhook payload and bypass signature verification by leveraging timing attacks or exploiting loose hash comparisons?
- **Pass Rationale**: Both internal and gateway webhook integrations strictly utilize `hash_equals()` for constant-time string comparison. The integrations also enforce mandatory timestamp binding and a hardcoded replay window (`MAX_TIMESTAMP_SKEW` / `300` seconds) to prevent replay attacks on valid payloads. The payload is hashed without prior deserialization/reserialization, preserving byte-for-byte integrity.
- **Reference**: `WebhookInboundProcessor::validateRequest`, `StripeGateway::verifyWebhook`.

## 2. SMS Payload Replay (MFS Ingestion)
- **Investigation**: The initial vulnerability analysis suggested a critical replay vector because `SmsParserService::decryptSmsPayload` implements AES-256-GCM without utilizing Additional Authenticated Data (AAD) to bind the device or timestamp. The deduplication check runs against the unencrypted outer envelope timestamp. Therefore, an attacker could intercept an encrypted payload, change the outer timestamp to bypass deduplication, and replay the payload.
- **Pass Rationale**: A rigorous refutation proved that while the cryptographic flaw does allow a replay past the parser tier, the downstream business logic neutralizes the impact. The parser reliably extracts the exact same provider `trx_id` from the identical decrypted SMS text. When the `SmsVerificationJob` cron attempts to match the transaction via `TransactionRepository::findByProviderTrxId`, it resolves to the already completed transaction. Because the job enforces a strict `$transaction['status'] === 'pending'` check, the replayed SMS is safely discarded and cannot be used to pay a second invoice. The lack of AAD is not exploitable for double-spending.
- **Reference**: `SmsVerificationJob::run` (L129).

## 3. Custom Domain & Webhook SSRF / DNS Rebinding
- **Investigation**: Does the outbound webhook dispatcher prevent Server-Side Request Forgery against internal networks, and does it resist Time-of-Check to Time-of-Use (TOCTOU) DNS Rebinding attacks?
- **Pass Rationale**: The `UrlValidator` implements exhaustive checks against RFC 1918 segments, link-local metadata addresses (169.254.x.x), and loopbacks. Crucially, the system mitigates DNS rebinding by performing a single DNS resolution, verifying the IP address, and explicitly pinning that resolved IP to the cURL handler via `CURLOPT_RESOLVE`. The connection dialed is cryptographically guaranteed to be the validated public address.
- **Reference**: `UrlValidator::resolveSafeWebhookIp` and `WebhookDispatcher::doSend`.

## 4. Hook Template Injection Privilege Escalation
- **Investigation**: An initial finding highlighted that `SmsParserService::attemptParse` passes parsing templates through an event hook (`mfs.templates`), allowing a malicious plugin to inject arbitrary template matches and bypass the database whitelist.
- **Pass Rationale**: Refutation confirmed that plugins can only be installed and activated by a Super Administrator. The OwnPay threat model inherently trusts the Super Administrator, as they hold ultimate authority over the instance. Because no external actor or low-privileged staff member can interact with or inject into the plugin lifecycle, this is not a valid privilege escalation vector.
- **Reference**: `SmsParserService::attemptParse`, `PluginController::install`.
