Advisory-management run for GHSA-r4rg-83vc-9wvw (own-pay/OwnPay).

Token wiring (this is what changed since the last run): the workflow on the dev ref checks out `ref: dev` and passes `GH_TOKEN: ${{ secrets.ADVISORY_PAT }}`. The pullfrog action reads `GH_TOKEN` as its external token override (`utils/token.ts` -> `resolveTokens`) and uses that one credential for git, the MCP GitHub tools and the `gh` CLI. The agent shell does NOT receive the PAT, so make every advisory and fork call through the MCP GitHub tools or the `gh` MCP tool, not through curl in the shell. If a call still answers 403 "Resource not accessible by integration", report the exact error and stop instead of working around it.

What the previous runs could not do, and what to do now:

1. `GET /repos/own-pay/OwnPay/security-advisories/GHSA-r4rg-83vc-9wvw` and read the draft.
2. `POST /repos/own-pay/OwnPay/security-advisories/GHSA-r4rg-83vc-9wvw/forks` to create the private fork `own-pay/OwnPay-ghsa-r4rg-83vc-9wvw`. The remediation has to live in that fork: push the fix branch `pullfrog/686-bitpay-webhook-reference-binding` (head `3a2af6bc578ab83e1330cb3b6ce064de1fc7a7a6`) from the upstream repo into the fork, and make sure the fork branch the advisory points at contains the fix.
3. `PATCH` the draft with the values below.
4. `POST /repos/own-pay/OwnPay/security-advisories/GHSA-r4rg-83vc-9wvw/publish`.

Values that match this repo's six published advisories (ecosystem `ownpay`, name `own-pay/ownpay`, patched_versions `0.3.0`):

- package: ecosystem `ownpay`, name `own-pay/ownpay`
- `vulnerable_version_range` `<= 0.2.1`, `patched_versions` `0.3.0`
- `severity` `high`
- CVSS 3.1: `AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:H/A:N` = 7.5
- summary, only if the draft carries none: "OwnPay: callback-supplied invoice id interpolated into a credential-bearing BitPay invoice lookup URL lets an attacker substitute their own token and settle a pending order via unauthenticated POST /webhook/bitpay"

Re-read the draft first and prefer its own text for summary and description; fall back to the values above only for the fields it lacks.

Two caveats to respect before publishing:

- **Scoping.** The draft body was unreadable when the fix was written, so the remediation was scoped from issue #686 finding 93 (the callback invoice identifier is inserted into a credential-bearing URL without path encoding; query and fragment delimiters in that identifier can replace the configured merchant token with an attacker-controlled token, allowing an invoice belonging to another account to authorize local settlement) plus the core rule that a webhook settles only on the provider-authenticated reference. If the draft describes a different site, do NOT publish. Report instead that the branch hardens the same attack surface rather than remediating the draft, and say what is needed.
- **Disclosure.** Issue #686 and PR #704 are public and describe the flaw in detail, so the details behind this advisory are already out; publishing is a documentation step here, not the disclosure gate.

Context:

- The remediation is PR #704 (base `dev`, head `3a2af6bc578ab83e1330cb3b6ce064de1fc7a7a6`); it is still open, so the advisory fork is where the patched code has to land.
- The workflow fix is PR #706, already merged into `dev`.
- Post a short summary comment on PR #704 describing what the advisory run did, and report here the exact request and response of every advisory and fork call, including failures.
