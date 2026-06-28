# Task Plan: Refactor WordPress WooCommerce bKash Plugin to Native OwnPay Payment Gateway

## Goal
A secure, fast, and fully working WordPress plugin for WooCommerce that processes payments through the OwnPay gateway natively, featuring clean OwnPay branding, transaction history monitoring, and secure signature-validated webhooks.

## Current Phase
Phase 8: Media Uploader Integration (Completed)

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent & WooCommerce plugin structure
- [x] Identify API constraints (endpoints, parameters, headers)
- [x] Identify Webhook verification constraints (hmac sha256, X-Signature header)
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define refactoring approach & namespace hierarchy
- [x] Create implementation plan artifact for user approval
- **Status:** complete

### Phase 3: Implementation
- [x] Implement the core gateway class (`includes/class-opwc-payment.php`)
- [x] Implement hooks/actions class (`includes/class-opwc-hooks.php`)
- [x] Implement webhook receiver and signature verification logic
- [x] Implement admin settings page and custom payment logs/table
- [x] Add OwnPay logos and assets
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify PHP compilation and lint checks
- [x] Verify WooCommerce payment gateway lifecycle (checkout -> redirect -> webhook/return -> order update)
- **Status:** complete

### Phase 5: Delivery & Documentation
- [x] Clean up deprecated files/folders
- [x] Prepare readme and installation instructions
- **Status:** complete

### Phase 6: Update Plugin Metadata & Rebranding
- [x] Rename main plugin file `ownpay-payment-gateway-woocommerce.php` to `ownpay-wordpress.php`
- [x] Update plugin name to "OwnPay-WordPress" in plugin headers, class docs, and text domains
- [x] Update URLs in headers: GitHub to `https://github.com/own-pay/OwnPay-WordPress`, URI to `https://wordpress.org/plugins/ownpay-wordpress`, Author URI to `https://ownpay.org`
- [x] Update License to AGPL 3 (AGPL-3.0 or later)
- [x] Simplify webhook slug callback to `woocommerce_api_ownpay` (giving URL: `/?wc-api=ownpay`)
- [x] Review base URL option configuration in settings
- [x] Document manual webhook registration instructions for merchants
- **Status:** complete

### Phase 7: Custom Logo Options & Admin Layout Fixes
- [x] Add custom logo URL setting option in WooCommerce payment settings
- [x] Implement `get_icon()` in `OPWC_Payment` class with fixed display dimensions
- [x] Revert plugin license back to `GPLv2 or later` in plugin header and files
- [x] Tweak sidebar CSS in `admin/css/opwc-admin-common.css` to restrict shield menu icon size to `20px x 20px`
- [x] Rebrand and rewrite `README.txt`
- **Status:** complete

### Phase 8: Media Uploader Integration
- [x] Enqueue `wp_enqueue_media()` and `opwc-admin-upload.js` uploader script in admin panel
- [x] Implement `generate_image_upload_html()` method inside `OPWC_Payment` class
- [x] Convert `custom_logo` field type to `image_upload`
- [x] Re-verify all lint checks
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use `opwc` prefix | Shorthand for OwnPay WooCommerce, cleanly separates from legacy `pgbw` prefix. |
| Use standard WC_API | Avoids registering custom route rewrites, leverages WooCommerce's native webhook callback entry point. |
| Rename to `ownpay-wordpress.php` | Aligns file names directly with official plugin slug and repo. |
| Constrain logo size in get_icon | Avoids page layout breakage on client checkout even if the uploaded logo is huge. |
| Custom image_upload generator | Reuses WordPress's media frame library for clean integration without custom file upload APIs. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
