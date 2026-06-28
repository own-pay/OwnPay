# Task Plan: Fresh Audit Bug Fixes v4

## Goal
Implement fixes for all identified bugs (currency validation, wp_error escaping, HPOS order edit url, fallback transaction id, and admin sidebar layout breakage).

## Current Phase
Completed

## Phases

### Phase 1: Implementation
- [x] Fix currency validation in `handle_webhook()` and `sync_payment_status()` in `includes/class-opwc-payment.php`
- [x] Escape WP_Error messages in `process_payment()` in `includes/class-opwc-payment.php`
- [x] Fix HPOS order edit URL in `admin/partials/views/payment-list/payment-table.php`
- [x] Implement fallback transaction ID in `sync_payment_status()` in `includes/class-opwc-payment.php`
- [x] Fix layout breakage by enqueuing admin styles globally and hardening menu image CSS selectors in `admin/class-opwc-admin.php` and `admin/css/opwc-admin-common.css`
- **Status:** complete

### Phase 2: Testing & Verification
- [x] Run PHP lint checks on modified files.
- [x] Attest the plan.
- **Status:** complete
