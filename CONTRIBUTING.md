# Contributing to Own Pay

Thank you for your interest in contributing to **Own Pay**! As an enterprise-grade, self-hosted payment gateway automation platform, we value high-quality contributions that maintain the stability and security of the system.

## ⚖️ License
By contributing to this repository, you agree that your contributions will be licensed under the **GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)**.

## 🛠️ Getting Started

### Prerequisites
- **PHP 8.2+**
- **Composer**
- **MySQL 8.0+** or **MariaDB 10.6+**
- **Laragon** (recommended for Windows users) or any PSR-compliant web server.

### Setup
1. Fork the repository.
2. Clone your fork: `git clone https://github.com/your-username/ownpay.git`
3. Install dependencies: `composer install`
4. Copy `.env.example` to `.env` and configure your database.
5. Run migrations (if applicable) or use the built-in installer.

## 🏗️ Architecture
Own Pay is built on a custom, lightweight, PSR-11 compliant framework with a heavy focus on **Hooks and Filters** (WordPress-style).

- **Core**: Located in `src/Core/`.
- **Controllers**: Located in `src/Controller/`.
- **Services**: Located in `src/Service/`.
- **Plugins**: Located in `plugins/`.
- **Templates**: Twig-based, located in `templates/`.

## 🎨 Coding Standards
We follow **PSR-12** coding standards. Please ensure your code is clean and well-documented.

- Use **strict types**: `declare(strict_types=1);` should be at the top of every PHP file.
- Use **meaningful variable names**.
- Add **docblocks** for complex logic.
- Avoid **global state**.

## 🔌 Developing Plugins
If you are adding a new gateway, please create it as a plugin. 
1. Create a directory in `plugins/`.
2. Implement the `PluginInterface`.
3. Use `EventManager` hooks to hook into the checkout or admin flows.

## 📮 Pull Request Process
1. Create a new branch: `git checkout -b feature/your-feature-name`.
2. Make your changes and commit with descriptive messages.
3. Push to your fork: `git push origin feature/your-feature-name`.
4. Open a Pull Request against the `dev` branch of the original repository.
5. Ensure your PR passes all CI checks.
6. A maintainer will review your code.

## 🛡️ Security
If you find a security vulnerability, **do not open a public issue**. Please follow the instructions in [SECURITY.md](SECURITY.md).

## 💬 Community
For general questions or discussions, please use the GitHub Discussions tab.

---
Thank you for helping make Own Pay the best self-hosted payment infrastructure!
