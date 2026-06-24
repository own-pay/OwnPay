# Findings - Compact Rules

## Existing Rule Sizes
The current rules in `.agents/rules/` are:
1. `agent-operating-rules.md` (~12 KB)
2. `api-design.md` (~4 KB)
3. `architecture-rule.md` (~4 KB)
4. `business-model-scoping.md` (~3.3 KB)
5. `code-review.md` (~3.7 KB)
6. `code-standards-architecture.md` (~4 KB)
7. `database-schema.md` (~2.6 KB)
8. `developer-workflows.md` (~8.5 KB)
9. `documentation-sync.md` (~1.9 KB)
10. `double-entry-ledger.md` (~2.5 KB)
11. `graphify.md` (~0.9 KB)
12. `planning-with-files.md` (~2.3 KB)
13. `powershell-syntax.md` (~3.8 KB)
14. `security-audit-part2.md` (~11 KB)
15. `security-audit.md` (~9.4 KB)
16. `security-cryptography.md` (~7.7 KB)
17. `web-security-performance.md` (~2.5 KB)
18. `white-label-domains.md` (~2.5 KB)

Total size is around 89 KB, which is approximately 20k to 25k tokens depending on the tokenizer. The user states that rules use too many tokens (representing 90.1% of the system/user context in their interface, totaling 18,013 tokens).

## Observations from business-model-scoping.md
- Frontmatter exists (`trigger: always_on`).
- Written in a descriptive, verbose prose style.
- Contains explanatory text, code blocks, and context which can be made significantly more compact.
- E.g., we can use compressed language, shorthand, and remove wordy explanations while retaining the exact core directives, exact names of tables, variables, and API calls.
