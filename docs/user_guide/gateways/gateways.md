# Payment Gateways

> **Purpose:** Connect API-based payment processors (like Stripe or PayPal) and set up local manual wallet gateways (like bKash Personal or Nagad Personal).

---

## Overview

The Payment Gateways page is where you control how your customers can pay. You can install automated API gateways or create custom manual gateways. Manual gateways support dynamic input fields (e.g. Transaction ID, Phone Number) and can integrate with the OwnPay companion app for automated SMS-based transaction verification.

![Payment Gateways List](./screenshots/gateways-list-updated.png)

---

## Getting Here

To access the Payment Gateways page:
1. Log in to the OwnPay admin dashboard.
2. Under the **GATEWAYS** section in the left sidebar, click **Payment Gateways**.

---

## Page Sections

The Payment Gateways panel contains three main views:

### 1. Gateways List View
The master list of all configured and available gateways:
* **All Gateways Tab:** Lists all 15 built-in API gateways and any manual gateways you have configured.
* **API Gateways Tab:** Shows only processors with structured integrations (Stripe, bKash API, PayPal, etc.).
* **Manual Gateways Tab:** Shows local wallet transfers and payment methods you have configured.
* **Search / Filter Tools:** Locate gateways by name or filter by active status.

### 2. Manual Gateway Creation
Located by clicking the **Add Manual Gateway** button:
* **Basic Info:** Name, API slug, and payment instructions.
* **Branding:** Upload a logo, set custom colors, and optionally provide a payment QR code.
* **Limits & Verification:** Define transaction range bounds and toggle **SMS Verification**.
* **Input Fields:** Dynamic builder to add fields that checkout customers must fill out.

![Create Manual Gateway Empty](./screenshots/create-manual-empty.png)

---

## Fields & Options Reference

### Manual Gateway Form Fields
| Field / Control | Type | Required? | Default | Description |
|---|---|---|---|---|
| **Gateway Name** | Text Input | Yes | — | The display name shown to customers (e.g. `Nagad Personal`). |
| **Slug** | Text Input | Yes | — | Lowercase alphanumeric code with hyphens used by API endpoints. |
| **Payment Instructions** | Text Area | No | — | Explanatory steps shown to customers at checkout. |
| **Logo** | File Upload | No | — | Icon representing the gateway in checkout screens. Prefix logo paths with `/storage/` when referencing. |
| **QR Code** | File Upload | No | — | Optional QR code scanned by customers to execute payment. |
| **Primary/Secondary Color** | Color Picker | No | `#e2136e` | Custom colors matching the wallet provider brand. |
| **Min Amount** | Number | No | `0` | Minimum transaction limit. Set to `0` for unlimited. |
| **Max Amount** | Number | No | `0` | Maximum transaction limit. Set to `0` for unlimited. |
| **Enable SMS Verification** | Checkbox | No | Unchecked | If enabled, routes transactions for auto-verification via paired devices. |
| **+ Add Field** | Button | No | — | Dynamically appends extra text/number fields for customer checkout inputs. |

---

## Step-by-Step: How to Use This Page

### Installing an API Gateway
1. Navigate to the **Payment Gateways** page.
2. Select the **API Gateways** tab.
3. Locate the target processor (e.g., **Stripe** or **PayPal Checkout**) and click **Install**.
4. Navigate to the configuration page to enter API keys and credentials. Save and set the status to **Active**.

### Creating a Manual Gateway
1. Click the **Add Manual Gateway** button in the header.
2. Enter the **Gateway Name** (e.g. `Nagad Personal`) and **Slug** (e.g. `nagad-personal`).
3. Under **Payment Instructions**, type how customers should transfer funds (e.g., "Please send money to 01800000000").
4. Choose a custom **Primary Color** matching the wallet brand.
5. Set transaction bounds under **Min Amount** and **Max Amount**.
6. Check **Enable SMS Verification** to automate payment approvals.
7. Click **+ Add Field** to append custom fields like "Transaction ID" or "Phone Number" that the customer must fill.
8. Click **Create Gateway** to save.

![Create Manual Gateway Filled](./screenshots/create-manual-filled.png)

---

## Configuration Guide

* **SMS Auto-Verification Mechanism:**
  * To automate manual gateways:
    1. Check **Enable SMS Verification** when creating or editing the manual gateway.
    2. Pair your mobile device under **Mobile & SMS → Paired Devices**.
    3. Define parsing templates under **Mobile & SMS → SMS Center** matching transaction SMS patterns.
    4. When customers input their transaction ID, the system will match it against parsed incoming SMS to approve payments automatically.

---

## Best Practices

- ✅ **Do:** Configure custom logo paths and check manual gateway colors to make the checkout screen look premium.
- ✅ **Do:** Add input fields like "Transaction ID" to let you verify payments manually if SMS sync fails.
- ❌ **Don't:** Set maximum amounts lower than minimum amounts.
- ❌ **Don't:** Use space characters in the slug field (only lowercase letters, numbers, and hyphens).

---

## Must Do

> ⚠️ Always prefix manually uploaded logo and QR code assets with `/storage/` in your theme files to prevent broken images.

---

## Optional / Can Skip

- QR codes can be omitted if you only show text transfer instructions.

---

## Common Mistakes & Troubleshooting

| Symptom | Likely Cause | Fix |
|---|---|---|
| Image is broken on the checkout screen | The logo path does not include the `/storage/` prefix. | Edit the manual gateway and check that the path resolves. |
| Customer transactions do not auto-approve | SMS verification is enabled, but the mobile parser companion app is offline or the regex parsing rule under SMS Center is incorrect. | Re-verify parser status under Paired Devices or approve the transaction manually in the Transactions panel. |

---

## Related Pages

- [Transactions](../payments/transactions.md) — View and approve incoming payments.
- [Paired Devices](../mobile-sms/devices.md) — Connect mobile device for SMS auto-verification.
- [SMS Center](../mobile-sms/sms-templates.md) — Set up SMS parsing heuristics.
