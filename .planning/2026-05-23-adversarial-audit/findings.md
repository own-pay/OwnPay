# Findings & Decisions

## Requirements
- Conduct adversarial audit of OwnPay codebase covering Security, Business Logic, Code Quality, Architecture, and Compliance.
- Produce `audit_map.md` and `audit_plan.md` first.
- Deliver final `own_pay_audit_report.md`.

## Research Findings
- **Project Root & Entry Point:** Root at `C:\laragon\www\ownpay`. Request entry point is `public/index.php` routing through `OwnPay\Kernel`.
- **Router:** `OwnPay\Http\Router` compiles route configurations from `config/routes/web.php` and `config/routes/api.php`.
- **Dependencies:** Core dependencies include Twig (^3.26.0), Ramsey UUID (^4.9), Firebase PHP-JWT (^7.0), chillerlan/php-qrcode (^5.0), and vlucas/phpdotenv (^5.6).
- **Security Check Command:** `composer audit --format=json` mapped in composer.json.
- **Database Layer:** Uses a custom `Database` connection wrapper under `OwnPay\Core\Database` with EventManager query hooks.
- **Defect 1 (Floating Point Defect - Domain B/C):** `InvoiceService.php` uses native floating-point math for invoice calculations, creating a minor precision risk.
- **Defect 2 (Suspended Merchant - Domain B):** `BearerAuthMiddleware` and `DomainMiddleware` do not check if a merchant is suspended, letting suspended brands use APIs and collect payments.
- **Defect 3 (Missing FK on Invoices - Domain D):** `op_invoices` table lacks a foreign key constraint linking to `op_customers`, unlike `op_transactions`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Mark node_modules optional in phpstan.neon | Allows static analysis tool PHPStan to run without error in environments without node_modules. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| PHPStan missing node_modules directory | Appended (?) to node_modules in phpstan.neon configuration. |

## Resources
- [composer.json](file:///C:/laragon/www/ownpay/composer.json)
- [public/index.php](file:///C:/laragon/www/ownpay/public/index.php)
- [src/Kernel.php](file:///C:/laragon/www/ownpay/src/Kernel.php)
- [src/Http/Router.php](file:///C:/laragon/www/ownpay/src/Http/Router.php)
- [config/routes/web.php](file:///C:/laragon/www/ownpay/config/routes/web.php)
- [config/routes/api.php](file:///C:/laragon/www/ownpay/config/routes/api.php)


