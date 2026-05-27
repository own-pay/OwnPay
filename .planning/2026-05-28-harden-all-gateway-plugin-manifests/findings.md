# Findings & Decisions

## Requirements
- Ensure all 53 payment gateway plugin manifests are complete, valid, and fully compliant with OwnPay's core architecture.
- Verify entrypoint filenames, PSR-4 namespaces, capabilities, required versions, Content Security Policies (CSP), and permissions.

## Research Findings
- The core `PluginManifest` loader expects metadata parameters like `name`, `slug`, `version`, `type`, `entrypoint`, `namespace`, `capabilities`, `requires`, `category`, `color`, `csp`, `permissions`, and `icon`.
- Missing keys (e.g. `csp`, `category`, `permissions`) block gateways from dynamic loading or dashboard rendering, and fail core verification constraints.
- Content Security Policy directives (`script_src`, `style_src`, `frame_src`, `connect_src`) must be fully defined inside each manifest's `csp` object to satisfy `SecurityHeadersMiddleware`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Populate full fields across all 53 manifests | Preserves structural consistency and compliance under static security analyses and dynamic browser sandbox checks. |
| Meticulous Class Loading Validation | Verifies class files on disk correspond exactly to manifest attributes, avoiding class not found errors during runtime bootstrap. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Missing manifest parameters | Ran the bulk injection and verified using rigorous assertion scripts. |
