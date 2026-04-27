# OwnPay Security Instructions (Zero-Trust)

Security is mandatory. If a convenient approach conflicts with security, choose security.

## 1) File System Safety
- Never dynamically include/require files from untrusted input without strict validation.
- For any file operation (`include`, `require`, `unlink`, `rmdir`, reads/writes):
  1. resolve via `realpath()`
  2. verify resolved path is within an allowed base directory
  3. reject on failure or boundary escape
- Never trust user-provided paths, filenames, slugs, or traversal patterns.

## 2) XSS Prevention
- Escape all user-supplied or DB-originated HTML output with:
  - `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`
- Avoid raw echoing of untrusted data into HTML, attributes, or inline scripts.
- Encode context-appropriately (HTML, attribute, URL, JS string) where applicable.

## 3) Command Execution Safety
- Do not use `shell_exec()`, `exec()`, `system()`, backticks, or unsafe shell interpolation with user input.
- If process execution is unavoidable, prefer safe APIs and argument separation (`proc_open` with controlled args).
- Reject or sanitize untrusted inputs with strict allowlists.

## 4) Dynamic Class/Module Loading
- Validate slugs/identifiers against strict allowlists (e.g. alphanumeric + `_` + `-`).
- Resolve class names from trusted maps/registries, not direct user input.
- Verify class exists and implements expected interface before instantiation.

## 5) Input Validation and Authorization
- Validate all external input at boundaries (HTTP, CLI, webhook, queue).
- Enforce server-side authorization checks for sensitive actions.
- Never rely on client-side checks for security decisions.

## 6) Secrets and Sensitive Data
- Never hardcode credentials, API keys, or tokens.
- Use environment/config secrets management.
- Avoid logging sensitive values (full PAN, secrets, tokens, private keys, auth headers).

## 7) Payments and Data Handling
- Treat payment and PII data as highly sensitive.
- Minimize data retention and exposure surface.
- Prefer tokenization patterns and least-privilege access.

## 8) Security Review Triggers (Always Flag)
- Unescaped HTML output
- Dynamic include/delete without `realpath` boundary checks
- Dangerous command execution patterns
- Weak randomness or insecure crypto usage
- Missing auth checks on privileged endpoints