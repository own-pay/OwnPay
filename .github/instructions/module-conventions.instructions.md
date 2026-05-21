# OwnPay Module Conventions

## 1) Module Placement
- Gateways go in: `modules/gateways`
- Addons go in: `modules/addons`
- Plugins go in: `modules/plugins`
- Themes go in: `modules/themes`
- Shared core cross-cutting services belong in: `src/`

## 2) Module Design
- Use explicit contracts/interfaces for module entrypoints.
- Keep module internals encapsulated.
- Avoid reaching into another module’s private internals.
- Expose extension hooks intentionally and document expected payloads/contracts.

## 3) Discovery and Loading
- Module discovery should rely on trusted metadata/configuration.
- Validate module identifiers with strict allowlists.
- Avoid arbitrary class/file loading from raw request parameters.

## 4) Config and Environment
- Keep module config explicit and validated.
- No secrets in module code or committed config files.
- Respect environment-driven behavior and defaults.

## 5) Upgrade Safety
- New module features must not break existing module contracts unexpectedly.
- Prefer additive changes over breaking ones.
- Document migration/upgrade steps when contract changes are unavoidable.