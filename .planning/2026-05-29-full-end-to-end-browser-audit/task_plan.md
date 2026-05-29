# Task Plan - Full End-to-End Browser Audit (Deep Security Focus)

## Goal
Conduct an exhaustive end-to-end browser audit of OwnPay (unauthenticated & authenticated) to map all pages, click all interactive elements, document all console/network errors or leaks, and subsequently fix every discovered issue with pristine regression testing. Then, execute a deeper security audit (adversarial payloads, XSS/CSRF vectors, session leakage, token transmission, and business logic flaws) and remediate any newly uncovered vulnerabilities.

## Current Phase
Phase 4: Deeper Security and Vulnerability Probing

## Phases

### Phase 1: Discovery Audit (Unauthenticated & Authenticated)
- [x] State base URL (`https://ownpay.test`) and initialize browser context
- [x] Audit unauthenticated pages and document findings
- [x] Audit authenticated pages (log in as `admin`) and document findings
- [x] Produce structured Phase 1 Deliverable report
- **Status:** complete

### Phase 2: Codebase Remediation
- [x] Fix Critical Findings (None found)
- [x] Fix High Findings (CSP style violations & inline events)
- [x] Fix Medium Findings (None found)
- [x] Fix Low Findings (Favicon & Broken image logo)
- **Status:** complete

### Phase 3: Final Verification (Initial Audit)
- [x] Re-run static lints (`npm run lint`, `composer lint:twig`)
- [x] Run PHPUnit and PHPStan analysis
- [x] Browser verify that all fixes are complete and pristine
- [x] Generate final Walkthrough and resolve session
- **Status:** complete

### Phase 4: Deeper Security and Vulnerability Probing
- [x] Investigate session storage and cookie transport attributes (Secure, HttpOnly, SameSite) in the browser
- [x] Probe authentication endpoints and page transition routes for JWT or credential leaks
- [x] Inject advanced adversarial payloads (XSS, SQL injection, Directory Traversal) into inputs (e.g., brand forms, settings, search fields, SMS rules) and check console/DOM outputs
- [x] Evaluate API response payloads for excessive data exposure (PII leakage, internal database ids, debug objects)
- [x] Document all findings in an updated `findings_report.md`
- **Status:** complete

### Phase 5: Advanced Remediation & Final Verification
- [x] Implement robust server-side sanitization and validation for any flagged input fields
- [x] Harden security middleware and session cookie parameters as required
- [x] Re-run full test suites (PHPUnit, PHPStan level 9, stylelint, eslint, Twig-cs-fixer)
- [x] Browser-verify resolutions to ensure completely clean console and network traces
- [x] Update `walkthrough.md` with deep audit additions
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Terminated background Chrome profiles | Frees up locked resources to allow `chrome-devtools-mcp` to open a clean debug session. |
| Omit nonces from CSP style-src | Modern browsers ignore `'unsafe-inline'` for element styles when nonces are declared, so styles are safely enabled by omitting the nonce from `style-src` while strictly locking down `script-src`. |
| CSS logo fallback | Resolves broken cdn links, strips inline script event handlers (`onerror`), and improves rendering speed. |
| Referer-based Settings Save redirects | Fixes UX panel drift by redirecting submissions back to the developer tab when forms are sent from the Developer Hub. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| chrome-devtools-mcp locked profile | Terminated the blocking chrome.exe PIDs using `Stop-Process` inside PowerShell. |

