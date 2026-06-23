# Task Plan: Fix and augment frontend contribution mock data and routes

## Goal
Provide a fully functioning, interactive UI/UX preview environment for the designer under `docs/frontend_contribution/`, enabling seamless brand switching, audit trail scans, dispute viewing/resolving, and system update testing.

## Current Phase
Phase 3: Implementation

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach for session persistence and mock routing
- [x] Define mock data structure for missing entities
- **Status:** complete

### Phase 3: Implementation
- [x] Initialize PHP session in `preview.php`
- [x] Add missing mock routes (`/admin/brands/switch`, `/admin/audit-integrity/scan`, `/admin/balance-verification/run`, etc.)
- [x] Implement interactive system update simulation (`/apply` and `/status` endpoints)
- [x] Enrich dynamic router to support `admin/<resource>/show.twig` fallbacks
- [x] Add disputes, diagnostics, and system update variables to `mock_data.json`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify brand switching in the navbar updates context
- [x] Verify audit integrity scan changes alert states
- [x] Verify balance verification reconciles
- [x] Verify disputes render detail views and handle verdict posting
- [x] Verify system update drag slider triggers simulated installation progress
- **Status:** complete

### Phase 5: Delivery
- [ ] Finalize code review
- [ ] Deliver walkthrough to the user
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Enable PHP Sessions | Allows persisting active brand state and scan status between requests in the preview server. |
| Time-based update animation | Sleeping on POST and checking elapsed time on GET `/status` implements a realistic updater simulation without complex background threads. |
| Toggle Scan Safety | Alternating system security status on successive scans allows the designer to verify both secure and database tampering layout states. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

