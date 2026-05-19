# Changelog

## [v0.1.0] - 2026-05-20

### Added
- implement comprehensive admin dashboard, modular architecture, and SMS processing services
- implement admin dashboard UI and backend services for invoice and PDF generation while removing legacy tooling.
- scaffold initial framework architecture including core services, admin dashboard, payment gateways, and middleware components
- implement core framework architecture, plugin system, payment gateway services, and modular admin interface
- implement SMS template management, regex-based parsing, and longest-chain balance reconciliation services
- implement core MVC architecture with automated testing, modular cron jobs, and comprehensive admin dashboard UI
- implement Bearer authentication middleware and centralize request routing logic
- initialize agent skills library with comprehensive security, architecture, and development toolsets and remove license file
- add environment-based config loader, mobile pairing and SMS parsing schema, and update migration script
- introduce comprehensive modular Copilot configuration and PR guidelines for OwnPay development standards
- implement installation wizard with multi-layer lock system and initialize dashboard admin modules
- implement core system architecture including dashboard modules, RBAC management, and master schema definition
- implement core services, controllers, and middleware to support gateway rendering and transaction management
- implement SMS center infrastructure, system adapter, and SQL security workflow
- soa: add TenantScope trait to all 15 repositories
- soa: extend RequestContext with site config properties
- soa: extract TwoFactorMiddleware from adapter.php
- soa: extract PermissionMiddleware from adapter.php
- soa: extract CsrfMiddleware from adapter.php
- soa: extract SessionMiddleware from adapter.php
- enterprise remediation â€” security hardening, database cleanup, architecture improvements

### Changed
- soa: migrate frontend controllers to RequestContext
- soa: migrate 10 controllers to RequestContext (batch 3)
- soa: migrate 6 controllers to RequestContext (batch 2)
- soa: migrate 5 controllers to RequestContext (batch 1)
- soa: wire middleware classes into adapter.php

### Fixed
- sync composer.lock with composer.json
- remove .claude/settings.local.json, add to .gitignore, fix option escaping in permissions-list
- restore dotenv config, drain proc_open pipes, fix false return in GatewayRendererService
- delete app/install/.installed and add to .gitignore
- workflow: correct action versions for trivy and gitleaks
- security: resolve all semgrep findings and remove legacy architecture
- security: set SameSite=Lax on all admin cookies

<!-- recommended-semver-bump: minor -->

All notable changes to this project will be documented in this file.

