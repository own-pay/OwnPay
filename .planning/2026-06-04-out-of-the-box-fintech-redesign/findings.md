# Findings & Decisions

## Requirements
- Redesign the System Update page using a highly polished, out-of-the-box 3-column asymmetrical fintech control center design.
- Structure must avoid typical grid circle shapes and standard boxes, using slanted glassmorphic plates for version nodes and a Slide-to-Upgrade confirmed slider.
- Configuration options must use pill segmented controllers.
- Logs and version history must be clean, human-readable vertical feeds.

## Research Findings
- The `update/manifest.json` file contains information on current/available versions, signature, size, release date, and SQL migrations list.
- Twig templates are cached at `storage/cache/twig`, which must be cleared to view updates.
- Linter checks (`npm run lint`, `composer lint:twig`) must pass.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Asymmetric 3-Column Layout | Left: Telemetry & Permissions. Center: Check/Pre-Flight/Slide-to-Upgrade. Right: History timeline. |
| Slanted Version Plates | Skew effects (`skewX(-6deg)`) offer a custom, non-conventional Git pipeline visual look. |
| Slide-to-Upgrade Widget | Mouse/Touch drag-and-drop thumb confirmation protects operations and mimics premium iOS/Stripe actions. |
| Segmented Pills | Replaces traditional switches to look more like Stripe/Vercel configuration settings. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| CSS Linting | Ensure comma-free HSL/RGB colors are used to pass stylelint checks. |

