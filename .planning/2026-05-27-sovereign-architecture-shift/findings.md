# Findings: Sovereign Architecture Shift

We have completed the comprehensive codebase discovery to identify SaaS-like multi-tenant structures in OwnPay and design a seamless transition to the **Sovereign Single-Owner Model** with global defaults and shared device routing.

## 1. Gateway Configuration Scoping
* **Database Table**: `op_gateway_configs`
  * **Current Definition**: `merchant_id` is defined as `BIGINT UNSIGNED NOT NULL` with a unique constraint `uk_merchant_gw` (`merchant_id`, `gateway_id`).
  * **Proposed Alteration**: Change `merchant_id` to `BIGINT UNSIGNED DEFAULT NULL`. This allows global configurations to be stored under `merchant_id = NULL`.
* **Repository layer**: `src/Repository/GatewayConfigRepository.php`
  * Overload `findCredentialsBySlug`, `listActive`, `findForGateway`, and `listActiveForCheckout` to seamlessly fetch the brand-specific config first, falling back to the global default (`merchant_id IS NULL`) when empty.
* **Bridge service**: `src/Gateway/GatewayBridge.php`
  * Modifying `decryptCredentials` so it utilizes the updated `findCredentialsBySlug()` from the repository.

## 2. Shared Android Device Pool
* **Database Tables**:
  * `op_paired_devices`: Defines device credentials and status.
  * `op_device_pairing_tokens`: Defines pairing OTP tokens.
  * `op_sms_parsed`: Defines parsed incoming carrier SMS.
* **Proposed Alterations**:
  * Change `merchant_id` to `BIGINT UNSIGNED DEFAULT NULL` in `op_paired_devices`, `op_device_pairing_tokens`, and `op_sms_parsed` in `database/schema.sql` to support a centralized global shared device pool.
* **Repository layers**:
  * `src/Repository/PairedDeviceRepository.php`:
    * Enhance `findByUuid` and `findByDeviceId` to fallback to global lookup or return the record regardless of tenant context.
* **Cron Matching Job**: `src/Cron/SmsVerificationJob.php`
  * Currently, the matching job processes transactions strictly matching the parsed SMS's `merchant_id`.
  * **Dynamic Routing**:
    1. Look up the transaction by `trx_id` globally using a new `findByTrxId()` bypass.
    2. If found, automatically update the parsed SMS record's `merchant_id` to match the transaction's `merchant_id` context.
    3. Link the SMS and complete the transaction.
    4. For fallback matches (amount + gateway), query globally using a new `findPendingMatchGlobal` method on `TransactionRepository`. If matched, dynamically align the merchant contexts.
  * This allows **one single Android phone** to receiveBKash/Nagad carrier messages for all stores/brands, dynamically routing and automated-approving checkouts globally!

## 3. Backwards Compatibility & Verification
* **PHPUnit Tests**: All existing integration tests (which currently mock tenant-scoped CRUD operations) must remain 100% green and functional.
* **Static Analysis**: Verify using `phpstan` at strict level 9.
