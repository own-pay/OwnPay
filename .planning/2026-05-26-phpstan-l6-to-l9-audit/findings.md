# Findings & Decisions: PHPStan Level 6 to Level 9 Audit

## Requirements
- Compile net-new PHPStan Level 9 static analysis errors.
- Group all errors into taxonomy categories (T1-T11).
- Identify severe issues (`RUNTIME_BUG`, `LOGIC_BUG`, `LATENT_BUG`, `ANNOTATION_BUG`).
- Verify current branch status and asset linters.

## Research Findings
- Upgrading from level 6 to level 9 exposed exactly **2,131 net-new errors** in **201 files**.
- The level 6 analysis on the baseline commit was completely clean (**0 errors**).
- **Taxonomy Breakdown**:
  - **T1: Null safety**: 630 errors (dereferencing nullable objects, missing guards).
  - **T2: Return type violation**: 101 errors (actual return types do not match PHPDoc/strict signatures).
  - **T3: Parameter type mismatch**: 561 errors (incorrect types passed to methods/functions).
  - **T4: Property type violation**: 1 error (PluginManifest::$type expects string, mixed given).
  - **T5: Undefined symbol**: 2 errors (CronJobRunner dynamic caller on mixed type object::run()).
  - **T6: Dead code / unreachable**: 0 errors.
  - **T7: Array type violation**: 383 errors (offset accesses on potentially non-array values, mixed keys).
  - **T8: Union type narrowing failure**: 0 errors.
  - **T9: PHPDoc annotation conflict**: 0 errors.
  - **T10: Logic error**: 0 errors.
  - **T11: Other**: 453 errors (mostly missing string-cast conversions or mixed-to-int casts).

## Hardening & Verification Results
- **PHPStan Level 9 Analysis**: **0 errors / [OK] No errors** across all 249 PHP source files analyzed!
- **PHPUnit Suite**: All **402 integration/ledger/logic tests passed** flawlessly with 1,133 assertions!
- **Frontend Asset Linters**: ESLint and Stylelint returned **0 errors / 0 warnings** after running `npx stylelint --fix` to auto-resolve layout formatting.
- **Twig Template Linters**: Twig CS Fixer returned **0 notices, 0 warnings, 0 errors** across all 73 templates!

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Categorize by PHPStan regex string-match | Automating categorization prevents human classification drift and guarantees strict classification of all 2,131 errors. |
| Stylelint auto-fix execution | Running Stylelint with `--fix` safely resolved formatting rule violations in checkout.css and installer.css without manual error insertion. |
