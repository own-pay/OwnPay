# OwnPay Quick Guide

OwnPay is an enterprise-grade, open-source, white-labeled payment gateway platform. It operates on a single-owner, multi-brand model allowing businesses to manage payment gateways, transactions, customers, and companion mobile devices under their own custom domains.

This guide is hosted at [docs.ownpay.org/doc](https://docs.ownpay.org/doc). For the full, interactive API Reference and step-by-step developer walkthrough, please visit the [OwnPay Merchant API Integration Guide](https://docs.ownpay.org/reference/ownpay-merchant-api-integration-guide).

## Integration Flow

1. Create a payment intent using the Merchant API (`POST /api/v1/payments`) to retrieve a `checkout_url`.
2. Redirect the customer to the checkout page, and handle the real-time status update via HMAC-SHA256 verified webhook (IPN) notifications.

> [!TIP]
> Use the [OwnPay Merchant API Integration Guide & Reference](https://docs.ownpay.org/reference/ownpay-merchant-api-integration-guide) for testing sandbox endpoints, finding query schemas, and checking parameter definitions.

---

## Developer & Community Resources

### Documentation & Development
* **Merchant Integration Guide (Primary):** [OwnPay Merchant API Integration Guide](https://docs.ownpay.org/reference/ownpay-merchant-api-integration-guide)
* **Main Documentation Portal:** [learn.ownpay.org](https://learn.ownpay.org)
* **Detailed Integration:** [Detailed Integration](https://learn.ownpay.org/developer/)
* **Plugin Development Guide:** [Plugin Developer](https://learn.ownpay.org/developer/plugin-development)
* **Hooks & Filters Reference:** [Hook Reference](https://learn.ownpay.org/developer/hooks-reference)
* **SDKs & Third-Party Plugins:** [SDK and Plugins Catalog](https://ownpay.org/sdk-and-plugins)

### Code & Support
* **GitHub Repository:** [OwnPay on GitHub](https://github.com/own-pay/OwnPay)
* **Community Discussions:** [GitHub Discussions](https://github.com/own-pay/OwnPay/discussions)
* **Bug Reports & Issues:** [Report an Issue](https://github.com/own-pay/OwnPay/issues)

### Social & Community
* **Facebook Page:** [OwnPay Facebook Page](https://fb.com/ownpay.org)
* **Facebook Group:** [OwnPay Community Group](https://fb.com/groups/ownpay.org)
* **YouTube Channel:** [OwnPay YouTube Channel](https://youtube.com/@ownpayorg)
