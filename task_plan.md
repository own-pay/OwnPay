# Task: Fix All 56 Audit Bugs for Production

> Goal: Apply production-grade fixes for all 56 bugs identified during the 11-phase security audit.
> Constraint: Preserve all existing comments and docstrings. Update AGENTS.md and ARCHITECTURE.md after all fixes.

## Fix Batch 1: Boot & Router Core (Bugs 1–5) — `complete`
- `[x]` Bug 1: Kernel.php — Always throw RuntimeException for missing middleware (remove debug bypass)
- `[x]` Bug 2: BaseDto.php — Initialize nullable properties to null when missing from input
- `[x]` Bug 3: Request.php — Support IPv6 in isTrustedProxy() using filter_var FILTER_VALIDATE_IP
- `[x]` Bug 4: Response.php — Use array for Set-Cookie headers; send with replace=false
- `[x]` Bug 5: RouteHelper.php — Include port in URL reconstruction

## Fix Batch 2: Middleware Security Pipeline (Bugs 6–12) — `complete`
- `[x]` Bug 6: DomainMiddleware.php — Parse host properly for IPv6 (strrpos for last colon)
- `[x]` Bug 7: IdempotencyMiddleware.php — Only cache 2xx responses
- `[x]` Bug 8: MaintenanceMiddleware.php — Start session if needed before checking auth
- `[x]` Bug 9: PermissionMiddleware.php — Add /admin and /admin/fragment/{page} to permission map
- `[x]` Bug 10: RateLimiterMiddleware.php — Use atomic increment (INCR or INSERT ON DUPLICATE KEY)
- `[x]` Bug 11: RequestSignatureMiddleware.php / middleware.php — Remove from webhook group or make gateway-aware
- `[x]` Bug 12: SecurityHeadersMiddleware.php — Pass nonce to request attributes; add unsafe-inline fallback for checkout

## Fix Batch 3: Repositories & Tenant Security (Bugs 13–21)
- `[ ]` Bug 13: TenantScope.php — Strip merchant_id from update payload in updateScoped()
- `[ ]` Bug 14: BaseRepository.php — Replace keyword blocklist with parameterized query enforcement
- `[ ]` Bug 15: DisputeRepository.php — Fix column names to match op_disputes schema
- `[ ]` Bug 16: IdempotencyRepository.php — Fix column names to match op_idempotency_keys schema
- `[ ]` Bug 17: InvoiceRepository.php — Add merchant_id scoping to findUnpaidByNumber()
- `[ ]` Bug 18: MerchantUserRepository.php — Whitelist allowed column names in updateStaff()
- `[ ]` Bug 19: RateLimitRepository.php — Fix column names to match op_rate_limits schema
- `[ ]` Bug 20: SmsTemplateRepository.php — Whitelist orderBy values in listForAdmin()
- `[ ]` Bug 21: WebhookEventRepository.php — Fix column names to match op_webhook_events schema

## Fix Batch 4: Security & Authentication Services (Bugs 22–26)
- `[ ]` Bug 22: PermissionService.php — Use findScoped/deleteScoped for role deletion
- `[ ]` Bug 23: RequestValidator.php — Skip strip_tags for password/secret/signature fields
- `[ ]` Bug 24: InputSanitizer.php — Remove pre-escaping htmlspecialchars from html() ingestion
- `[ ]` Bug 25: LogSanitizer.php — Require Luhn check or length-context to prevent transaction ID redaction
- `[ ]` Bug 26: Authenticator.php — Defer login success logging until after 2FA verification

## Fix Batch 5: Brand Context, Domain & Theme (Bugs 27–30)
- `[ ]` Bug 27: BrandContext.php — Guard all $_SESSION references with session_status() check
- `[ ]` Bug 28: BrandThemeService.php — Return safe defaults when merchant is null
- `[ ]` Bug 29: DomainService.php — Strip port from host before calling gethostbyname()
- `[ ]` Bug 30: SettingsRenderer.php — Cast option values to string before escaping

## Fix Batch 6: Checkout Controllers & Payments (Bugs 31–37)
- `[ ]` Bug 31: CheckoutController.php — Make checkout_hash mandatory for cancel action
- `[ ]` Bug 32: PaymentIntentCheckoutController.php — Make checkout_hash mandatory for cancel action
- `[ ]` Bug 33: InvoiceCheckoutController.php — Initialize $twig before early return paths
- `[ ]` Bug 34: BaseRepository.php / InvoiceService.php — Cast LIMIT/OFFSET to int before binding
- `[ ]` Bug 35: CheckoutController.php + PaymentIntentCheckoutController.php — Accept form_html as valid response
- `[ ]` Bug 36: DisputeService.php — Return nullable array or handle null from findScoped()
- `[ ]` Bug 37: JwtService.php — Fix test suite parameter order (separate task, note only)

## Fix Batch 7: Mobile API & SMS (Bugs 38–39)
- `[ ]` Bug 38: Mobile DeviceController.php — Resolve device UUID from database before calling revoke()
- `[ ]` Bug 39: ConfigController.php + SmsTemplateRepository.php — Scope listActive() by tenant

## Fix Batch 8: Admin Controllers (Bugs 40–46)
- `[ ]` Bug 40: AuthController.php — Use MerchantUserRepository::getTotpSecret() for decryption
- `[ ]` Bug 41: Admin DomainController.php — Parse hostname from Host header before gethostbyname()
- `[ ]` Bug 42: FaqController.php — Scope FAQ writes to active brand merchant_id
- `[ ]` Bug 43: LedgerController.php — Resolve merchant's default currency dynamically
- `[ ]` Bug 44: DashboardController.php + PermissionMiddleware.php + TwoFactorMiddleware.php — Use dynamic login slug
- `[ ]` Bug 45: StaffController.php — Validate role_id belongs to active merchant
- `[ ]` Bug 46: TwoFactorSetupController.php — Decrypt TOTP secret before QR/view output

## Fix Batch 9: Public & Non-Mobile API (Bugs 47–50)
- `[ ]` Bug 47: Api Admin DeviceController.php — Fix parameter order in revoke() call; remove phpstan-ignore
- `[ ]` Bug 48: Api Admin DomainController.php — Fix parameter order in verifyDomain() call
- `[ ]` Bug 49: CommLogRepository.php — Remove non-existent 'attempt' column from retrySms()
- `[ ]` Bug 50: Api Admin SmsTemplateController.php — Fix listForAdmin column + updateTemplate argument types

## Fix Batch 10: Plugins, Cron & Auxiliary (Bugs 51–54)
- `[ ]` Bug 51: PluginSandbox.php — Append trailing directory separator to pluginDir path
- `[ ]` Bug 52: QueueWorkerJob.php — Check affected row count after lock update, skip if 0
- `[ ]` Bug 53: WebhookRetryJob.php — Update comm_log status to 'delivered' on successful retry
- `[ ]` Bug 54: BackupService.php + UpdateService.php — Use proper SQL statement parser (not explode)

## Fix Batch 11: Twig Templates & Frontend (Bugs 55–56)
- `[ ]` Bug 55: checkout.twig + checkout-status.twig — Use JSON_HEX_TAG flag or data attributes for JSON output
- `[ ]` Bug 56: _gateway-grid.twig — Use data attributes + JS binding instead of inline onclick with raw values

## Phase 12: Verification & Documentation
- `[ ]` Run PHP lint on all modified files
- `[ ]` Update AGENTS.md with new architectural fixes
- `[ ]` Update ARCHITECTURE.md if any patterns changed
- `[ ]` Create final walkthrough.md

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| (none yet) | | |
