# Own Pay v0.1.0

> Enterprise-grade Open-Source Payment Gateway — Multi-gateway, hook-based, PCI-compliant.

## Features

- **Multi-Gateway** — Stripe, SSLCommerz, bKash, Nagad, Rocket, UPay + manual gateways
- **Plugin Ecosystem** — WordPress-style hooks/filters with 80+ extension points
- **Mobile Companion** — SMS parsing, device pairing, real-time alerts
- **Premium Checkout** — Responsive, animated, per-merchant branded checkout
- **Built-in Addons** — SMS Gateway (Twilio/Vonage), Mail Gateway (SMTP/Mailgun/SendGrid), Telegram Bot
- **PCI DSS Compliant** — Argon2ID, CSRF, rate limiting, HMAC webhook verification
- **Enterprise Installer** — 4-step wizard with requirements check, schema import, admin seeding

## Requirements

- PHP ≥ 8.1 (8.3 recommended)
- MySQL 8.0+ / MariaDB 10.6+
- Extensions: bcmath, curl, gd, json, mbstring, openssl, pdo_mysql, fileinfo
- Composer 2.x

## Quick Start

```bash
git clone https://github.com/own-pay/ownpay.git
cd ownpay
composer install --no-dev --optimize-autoloader
# Point web root to public/
# Navigate to /install
```

## Architecture

```
src/              PSR-4 source (Container, Router, Services, Controllers)
config/           Routes, middleware, app config
modules/          Themes & addons (PluginInterface)
templates/        Twig templates (checkout, admin, email, install)
public/           Web root (index.php, assets)
tests/            PHPUnit test suites (Unit + Integration)
```

## Testing

```bash
composer install
php vendor/bin/phpunit --testdox
```

## License

AGPL-3.0-or-later
