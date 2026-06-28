# Findings: Refactoring wc-6amtech-payment-gateway-bkash to OwnPay

We have analyzed the existing WooCommerce bKash payment gateway plugin (`wc-6amtech-payment-gateway-bkash`) and the OwnPay platform's API/Webhook architecture. Below are the key findings and requirements for the refactoring.

## 1. Plugin Re-branding & Renaming
The plugin will be renamed from `6amTech - Payment Gateway for bKash and WC` to `OwnPay Payment Gateway for WooCommerce`.
- **Slug**: `ownpay-payment-gateway-woocommerce`
- **Main file**: `ownpay-payment-gateway-woocommerce.php` (renamed from `wc-6amtech-payment-gateway-bkash.php`)
- **Text Domain**: `ownpay-payment-gateway-woocommerce`
- **Prefixes**: Replace `pgbw_`, `PGBW_`, and `PGBW` with `opwc_`, `OPWC_`, and `OPWC` respectively.
- **Branding**: Update logos, names, links, and text references to OwnPay.

## 2. OwnPay API Architecture Integration
We need to connect WooCommerce checkout with the OwnPay API:
- **API URL**: Base URL of the OwnPay instance (configured in admin settings).
- **API Key**: Bearer token (e.g. `op_XXXXXXXX.YYYYYYYY...`) sent in `Authorization: Bearer <key>`.
- **Payment Initiation**:
  - Endpoint: `POST {api_url}/api/v1/payments`
  - Payload:
    - `amount`: float string
    - `currency`: WooCommerce currency code (e.g. `BDT`, `USD`)
    - `redirect_url`: Return URL after checkout success.
    - `cancel_url`: Cart/Checkout URL after cancel.
    - `callback_url`: Webhook endpoint (WC API).
    - `customer_email`, `customer_name`, `customer_phone`.
    - `reference`: WooCommerce Order ID.
  - Response:
    - Parses `checkout_url` to redirect the buyer.
    - Saves `payment_id` (UUID) and `token` as order meta.

## 3. Webhook (IPN) & Security
To prevent payment spoofing, the plugin must handle server-to-server webhook notifications securely:
- **Webhook Endpoint**: `https://example.com/?wc-api=opwc_webhook` (using WC_API mechanism).
- **Signature Headers**:
  - OwnPay core webhook sends header `X-Signature: sha256={signature}` or `X-OwnPay-Signature: {signature}`.
  - HMAC-SHA256 signature is generated using the Webhook Secret.
- **Verification**:
  - Read raw post body.
  - Verify signature against calculated HMAC using `hash_equals`.
  - Process order completion if status is paid/completed.

## 4. Admin Settings & Panel
WooCommerce gateway settings will require:
- **Enable/Disable**: Toggle gateway.
- **Title & Description**: Custom customer-facing texts.
- **API Endpoint URL**: URL of the OwnPay platform.
- **API Key**: API Bearer key.
- **Webhook Secret**: Signing key used to verify callbacks.
- **Complete Order After Payment**: Option to mark order Completed (status `completed`) vs Processing (status `processing`).

## 5. File Restructuring Plan
We will delete the old files and create a clean, modern WordPress plugin structure:
- `ownpay-payment-gateway-woocommerce.php` (Main plugin file)
- `includes/class-opwc.php` (Main plugin orchestrator class)
- `includes/class-opwc-loader.php` (WordPress hook registerer)
- `includes/class-opwc-payment.php` (WooCommerce Gateway subclass)
- `includes/class-opwc-hooks.php` (Frontend/Backend hooks, currency settings, order details)
- `includes/functions.php` (Database/Helper functions)
- `admin/class-opwc-admin.php` (Admin panel enqueues)
- `admin/class-opwc-menu-settings.php` (Admin menu pages)
- `admin/partials/class-opwc-payment-list.php` (Custom payment logs manager)
- `admin/partials/views/payment-list/payment-list.php`
- `admin/partials/views/payment-list/payment-table.php`
- `assets/logo/payment-method-logo.png` & `assets/logo/dashboard-menu-icon.jpg` (Update logos with OwnPay branding)
