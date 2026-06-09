# Task Plan: Classic Supporter Card Refinement & Single Download Enforcement

## Goal
Implement a refined classic dark-blue supporter badge layout with customized positioning, fixed BDT advances, and a strict "download once" constraint that permanently locks the card inputs upon download completion.

## Current Phase
Phase 5

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user layout preference from attached image
- [x] Identify classic typography and spacing coordinates
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Design and Planning
- [x] Create refined implementation_plan.md
- [x] Obtain user approval
- **Status:** complete

### Phase 3: Implementation
- [x] Implement single-download status tracking in `donate.php` (Session + Javascript)
- [x] Re-draw Canvas logic to match classic card spacing and centered logo
- [x] Update Pop-up Modal text labels to use localized Bengali prompt
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify requirements met (single download constraint + classic visual coordinates)
- [x] Document test results
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Shift logo to bottom-center | Recreates classic layout requested by the user, leaving the top spacious. |
| Monospace Verified ID Center-aligned | Fits cleanly below the supporter name gold divider. |
| Session + client flag block | Enforces the "download only once" rule robustly across reloads. |
