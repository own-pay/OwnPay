# Findings & Discovery — Global Gateway Plugin Audit

We systematically scanned all 123 payment gateway plugins within the OwnPay ecosystem (`modules/gateways/`) to identify structural bugs, logic flaws, timeout omissions, and security gaps.

## Critical Security Discoveries

We identified a critical security bypass pattern in **8 payment gateways**:
1. **Fawry** (`fawry`)
2. **Kushki** (`kushki`)
3. **Xendit** (`xendit`)
4. **Authorize.Net** (`authorize-net`)
5. **Giropay** (`giropay`)
6. **Paddle** (`paddle`)
7. **Sofort** (`sofort`)
8. **Trustly** (`trustly`)

### The Vulnerability: Silent UAT Bypass on cURL Failure
In all 8 gateway adapters, the `initiate()` method executes an outbound API request via cURL. If the API endpoint is down, slow, or blocked by a malicious request (causing a cURL timeout or non-200 HTTP status), the gateways immediately trigger an **unprotected early return fallback**, generating a successful payment redirect URL containing `status=PAID` and a `SIM_` transaction ID. 

Although some of these adapters have a check for `$mode === 'live'` further down in the method body, the cURL failure block returns **early**, entirely bypassing the environment check!

In production, this allows a malicious actor to:
1. Block connection to the gateway API endpoint (or trigger a timeout).
2. The gateway fails to connect, triggers the early return, and issues a valid redirect payload containing successful status.
3. The customer's order is instantly authorized as paid without any real funds changing hands.

## Severity Ratings Matrix

* **Critical**: Security bypasses (early return fallback to `SIM_` parameters in live production).
* **High**: Mixed offset access errors on JSON payloads (missing `is_array` checks that can crash the script).
* **Medium**: Timeout omissions or excessive values (> 30s) on cURL blocks causing thread pool exhaustion.
* **Low**: Formatting inconsistencies or minor docstring deviations.
