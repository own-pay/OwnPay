# Task Plan: ownpay.org Landing Page Redesign

## Goal
Create `new_design.html` in the `ownpay_org_landing_page` directory following `DESIGN.md` guidelines and implementing all requested user landing page improvements.

## Current Phase
Phase 2: Planning & Structure

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent (bento modal dimming, non-terminal deploy flow, nice map horizontal timeline, circle tooltips, premium footer)
- [x] Identify constraints (pure Tailwind + CSS variables, clean raw logos, mobile responsiveness)
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define design approach (HSL palette, Inter + Outfit fonts)
- [x] Create project structure
- **Status:** complete

### Phase 3: Implementation
- [ ] Set up stylesheet with HSL tokens
- [ ] Build Hero, Header, and compact "Backed by" Elite Sponsor block
- [ ] Build Deploy flow (01, 02, 03 cards) and 4 Feature Cards (including Enterprise Fintech)
- [ ] Build Bento Box Sponsors with JS focus/dimming and modal detail overlay
- [ ] Build Roadmap scroll Nice Map and sub-phases
- [ ] Build FAQ 2-column accordion and GitHub CTA rebrand
- [ ] Build Supporter Circles and multi-column Footer
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Verify responsiveness on mobile/tablet/desktop
- [ ] Document test results in progress.md
- **Status:** pending

### Phase 5: Delivery
- [ ] Review output page in browser
- [ ] Deliver the walkthrough
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| HSL Palette | Consistency with DESIGN.md recommendations for premium fintech branding |
| Horizontal Scroll roadmap | Fits 7 complex phases in a clean, scrollable horizontal viewport to avoid layout elongation |
| Custom Popover modal | Displays sponsor details natively without navigation overhead |

## Errors Encountered
| Error | Resolution |
|-------|------------|
