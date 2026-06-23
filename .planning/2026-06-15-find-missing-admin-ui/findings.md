# Findings & Decisions

## Requirements
Identify all parts of `docs/frontend_contribution/ADMIN-PANEL-MAP.md` that have backend logic (routes, controller methods, DB structure) but are missing administrative GUIs (menus, views, form inputs, buttons) in the actual templates.

## Research Findings
Here is the baseline of files discovered in the codebase:

### Admin Controllers (`src/Controller/Admin/`)
1. `ActivitiesController`
2. `AddonController`
3. `ApiKeyController`
4. `AuditIntegrityController`
5. `AuthController`
6. `BalanceVerificationController`
7. `BrandController`
8. `CurrencyController`
9. `CustomerController`
10. `DashboardController`
11. `DeveloperController`
12. `DeviceController`
13. `DisputeController`
14. `DomainController`
15. `FaqController`
16. `FeeRuleController`
17. `GatewayController`
18. `InvoiceController`
19. `LedgerController`
20. `PaymentLinkController`
21. `PluginController`
22. `RolesController`
23. `SettingsController`
24. `SmsDataController`
25. `SmsTemplateAdminController`
26. `StaffController`
27. `SystemUpdateController`
28. `ThemeController`
29. `TransactionController`
30. `TwoFactorSetupController`
31. `WebhookEventController`

### Admin Templates (`templates/admin/`)
1. `activities.twig`
2. `audit_integrity.twig`
3. `balance-verification.twig`
4. `customers.twig`
5. `dashboard.twig`
6. `my-account-2fa.twig`
7. `my-account.twig`
8. `reports.twig`
9. `sms-data.twig`
10. `system-update.twig`
11. `addons/index.twig`
12. `brands/edit.twig`
13. `brands/index.twig`
14. `customers/create.twig`
15. `customers/show.twig`
16. `dashboard/_setup_wizard.twig`
17. `developer/index.twig`
18. `devices/index.twig`
19. `disputes/index.twig`
20. `disputes/show.twig`
21. `domains/index.twig`
22. `fee-rules/create.twig`
23. `fee-rules/edit.twig`
24. `fee-rules/index.twig`
25. `gateways/create-manual.twig`
26. `gateways/edit-manual.twig`
27. `gateways/index.twig`
28. `invoices/edit.twig`
29. `invoices/index.twig`
30. `layout/base.twig`
31. `layout/footer.twig`
32. `layout/modals.twig`
33. `layout/navbar.twig`
34. `layout/sidebar.twig`
35. `ledger/index.twig`
36. `payment-links/edit.twig`
37. `payment-links/index.twig`
38. `plugins/index.twig`
39. `plugins/install.twig`
40. `plugins/settings.twig`
41. `roles/index.twig`
42. `settings/index.twig`
43. `settings/translate.twig`
44. `sms-center/edit.twig`
45. `sms-center/index.twig`
46. `staff/edit.twig`
47. `staff/index.twig`
48. `themes/index.twig`
49. `themes/install.twig`
50. `transactions/edit.twig`
51. `transactions/index.twig`
52. `webhooks/events.twig`
53. `webhooks/logs.twig`

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Audit settings fields | Analyze input controls inside `templates/admin/settings/index.twig` against actual settings schema / keys. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [ADMIN-PANEL-MAP.md](file:///c:/laragon/www/ownpay/docs/frontend_contribution/ADMIN-PANEL-MAP.md)

