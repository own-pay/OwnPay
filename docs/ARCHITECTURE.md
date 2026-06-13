# OwnPay Architecture

A developer's guide to how **OwnPay** is built — for contributors and integrators.

OwnPay is a **self-hosted, single-owner, multi-brand payment orchestrator** written in modern PHP (8.3+). One administrator runs the platform and creates multiple **brands** (stores); each brand has its own domain, gateways, customers, and ledgers, all isolated inside a single MySQL database by a `merchant_id` column. It is **not** a multi-tenant SaaS — there is no public sign-up.

> Looking to run it locally first? See **[LOCAL_SETUP.md](LOCAL_SETUP.md)**.

---

## 1. Tech stack at a glance

| Layer | Choice |
|-------|--------|
| Language | PHP 8.3+ (`declare(strict_types=1)` everywhere) |
| Persistence | MySQL 8 / MariaDB 10.4+ (PDO, prepared statements only) |
| Templating | Twig 3+ |
| Front controller | Single entry point `public/index.php` |
| DI | Custom PSR-11 container (`src/Container.php`) with reflection autowiring |
| Auth (mobile/API) | `firebase/php-jwt` |
| Frontend | Server-rendered Twig + vanilla CSS/JS (no SPA build step) |
| Dependencies | Intentionally minimal — see `composer.json` |
| Static analysis | PHPStan **level 9** |
| Tests | PHPUnit |

There is **no framework** (no Laravel/Symfony runtime). The kernel, router, container, and middleware pipeline are small, readable, first-party code under `src/`.

---

> Detailed Architecture comming soon.
