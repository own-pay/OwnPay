# Findings & Decisions - Fee Rules Admin UI

## Requirements
- Provide a CRUD interface for administrative management of fee rules in `op_fee_rules`.
- Enforce strict role-based access control (RBAC):
  - Superadmin can manage global rules (where `merchant_id IS NULL`) or brand-specific rules.
  - Brand-scoped staff users are restricted to their active `merchant_id` context.
- Support flat, percentage, and tiered types.
- Ensure the UI looks premium with dynamic interaction for tiered limits/fees.

## Research Findings
- Routes are registered in `config/routes/web.php` and mapped to `Admin\FeeRuleController`.
- Controllers extend `AdminPageTrait` to inherit rendering context (active brand, user, alerts, etc.).
- `PermissionMiddleware` handles authorization by mapping routes to permission strings (e.g. `/admin/settings` => `settings.view` / `settings.manage`). We must add `/admin/fee-rules` to this map.
- Currencies are queried using `CurrencyService::listAll()`.
- Gateways are queried using `GatewayRepository::listActive()`.

## Technical Decisions
- Map `/admin/fee-rules` routes to `settings.view` and `settings.manage` permissions.
- Create a new script `public/assets/js/pages/fee-rules.js` to manage the UI toggle and the dynamic tiered fee input fields.
- Set `merchant_id` to `NULL` for global rules created by superadmins, and set it to active tenant ID for brand-specific rules.
- Format amount caps, values, and limits carefully before inserting into the database to prevent PDO exceptions.
