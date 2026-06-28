# Findings: Fresh Audit of WooCommerce OwnPay Plugin (v4)

We have performed another deep, thorough audit of the WooCommerce OwnPay payment gateway plugin and identified the following critical security, usability, and HPOS-compatibility bugs.

## 1. Webhook & Redirect Currency Validation Missing (🔴 Critical)
- **Location**: `includes/class-opwc-payment.php`
- **Issue**: Neither `handle_webhook()` nor `sync_payment_status()` validates the currency of the paid transaction against the WooCommerce order currency.
- **Impact**: A user could make a payment of 100 BDT (approx. $0.80) to successfully complete a 100 USD order if the numeric amount matches.
- **Fix**: Verify that the currency (uppercase) received from the webhook/API matches the order currency.

## 2. Insecure WP_Error Printing (🔴 Critical)
- **Location**: `includes/class-opwc-payment.php` -> `process_payment()`
- **Issue**: The network failure message from `wp_remote_post` is printed directly:
  `$response->get_error_message()` inside `wc_add_notice()`.
- **Impact**: Potential XSS if the WP_Error contains raw HTML injected from network layers.
- **Fix**: Wrap it in `esc_html()`.

## 3. HPOS Compatibility: Hardcoded Order Edit URL (🟠 High)
- **Location**: `admin/partials/views/payment-list/payment-table.php`
- **Issue**: The order edit link is hardcoded as:
  `admin_url('post.php?post=' . $order_id . '&action=edit')`
- **Impact**: This breaks for WooCommerce stores utilizing High-Performance Order Storage (HPOS), rendering broken edit buttons.
- **Fix**: Use WooCommerce's `Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($order_id)` when available, falling back to legacy post URLs.

## 4. Fallback Transaction ID on Sync Redirect (🟡 Medium)
- **Location**: `includes/class-opwc-payment.php` -> `sync_payment_status()`
- **Issue**: If the webhook hasn't processed and `sync_payment_status()` completes the payment, the transaction API response might lack the `gateway_trx_id` or `trx_id` (if the gateway database has not processed it yet).
- **Impact**: The order is marked paid with an empty transaction ID.
- **Fix**: Fallback to using the cached `_ownpay_payment_id` UUID stored in order meta.
