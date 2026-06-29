---
name: vitepress-docs-site
description: Create ownpay-documentation VitePress site at C:\laragon\www\ownpay-documentation
metadata:
  type: project
---

# Task: VitePress Documentation Site - OwnPay

**Goal:** Create `ownpay-documentation` at `C:\laragon\www\ownpay-documentation` using VitePress 1.6.4. Incorporate existing user guide docs from `C:\laragon\backup\OwnPay\docs\user_guide\`. Output GitHub Actions CI/CD guide.

## Constraints

- VitePress 1.6.4 (latest stable 2026)
- No TypeScript config
- Domain: `learn.ownpay.org`
- GitHub repo: `github.com/own-pay/ownpay-docs`
- Must include all existing user guide docs
- Must add API Reference stubs (Authentication, Initiate Payment)
- Must add Architecture and Installation sections
- Screenshots must be copied alongside docs

## Phases

| Phase | Title | Status |
|-------|-------|--------|
| 1 | Initialize project (dir, git, npm, vitepress) | not_started |
| 2 | Create `.vitepress/config.mjs` with full sidebar/nav | not_started |
| 3 | Copy & adapt existing user guide docs + screenshots | not_started |
| 4 | Create API reference, architecture, installation, index.md | not_started |
| 5 | Create .gitignore | not_started |
| 6 | Run dev server and verify | not_started |
| 7 | Output GitHub Actions CI/CD guide | not_started |

## Directory Structure Target

```
ownpay-documentation/
├── .vitepress/
│   └── config.mjs
├── guide/
│   ├── introduction.md
│   ├── architecture.md
│   └── installation.md
├── user-guide/
│   ├── index.md
│   ├── auth/  (login.md, two-factor.md, forgot-password.md)
│   ├── dashboard/  (dashboard.md)
│   ├── payments/  (transactions.md, invoices.md, payment-links.md, ledger.md)
│   ├── gateways/  (gateways.md, currencies.md)
│   ├── people/  (brands.md, customers.md, staff.md, roles.md)
│   ├── mobile-sms/  (devices.md, sms-templates.md, sms-logs.md)
│   ├── reports-finance/  (reports.md, audit-log.md, balance-verification.md)
│   ├── appearance/  (branding-settings.md, landing-page.md, themes.md)
│   ├── system/  (settings.md, plugins.md, addons.md, domains.md, system-update.md)
│   ├── account/  (my-account.md)
│   └── public/  (checkout.md)
├── api-reference/
│   ├── index.md
│   ├── authentication.md
│   └── initiate-payment.md
├── public/
│   └── (images/screenshots)
├── index.md
├── package.json
└── .gitignore
```

## Errors Encountered

| Error | Attempt | Resolution |
|-------|---------|------------|
