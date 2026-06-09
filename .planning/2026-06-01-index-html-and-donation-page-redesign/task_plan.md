# Task Plan: index.html and Donation Page Redesign

## Goal
Fully redesign and optimize `index.html` to introduce highly premium, responsive, and SEO-optimized sections matching the design system of `donate.php`, adding multi-page routing, interactive bento sponsor modals, dynamic contributor circles, a horizontal Nice Map roadmap, and space-efficient FAQs.

## Current Phase
Phase 1: Requirements & Discovery

## Phases

### Phase 1: Requirements & Discovery
- [x] Read and comprehend user prompt requirements in full.
- [x] Analyze `index.html` structure, styles, and classes.
- [x] Research Elite Sponsor styles and structures in `donate.php` (line 2006+).
- [x] Document discoveries and requirements in `findings.md`.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Update `implementation_plan.md` with explicit details.
- [x] Draft layout designs for Bento Sponsors and horizontal roadmap.
- [x] Establish precise markup structures to preserve SEO (headings, meta tags).
- **Status:** complete

### Phase 3: Implementation
- [ ] Implement Hero trust chips (Open-Source) and reduce whitespace gaps.
- [ ] Redesign Elite Sponsor section in `index.html` to match `donate.php` backed-by horizontal style.
- [ ] Add matching GitHub CTA button after the waitlist form.
- [ ] Replace terminal UI deployment block with premium three-step card workflow.
- [ ] Add the new feature card for "Open-Source, Lightweight, global coverage, Enterprise grade Fintech".
- [ ] Implement Bento Box Sponsors Section between Features and Roadmap.
- [ ] Create the interactive sponsor popup detail overlay with dimming background effects.
- [ ] Redesign Roadmap into a compact horizontal horizontal-scrolling track or split grid including phases 1-7 & subtask 3.3.
- [ ] Change the GitHub CTA header and enhance layout with premium backdrop effects.
- [ ] Redesign FAQ section with a highly premium, compact grid accordion layout.
- [ ] Create the new Supporter & Contributors showcase with circle avatars and tooltip thank-you details.
- [ ] Re-engineer the multi-column, multi-page nav and social-rich Footer.
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Run Twig/HTML static lints.
- [ ] Emulate responsive behaviors on mobile, tablet, and desktop viewports.
- [ ] Test the Bento box sponsor popup and dimming logic.
- [ ] Verify SEO elements (heading hierarchy, titles, descriptive meta, unique IDs).
- **Status:** pending

### Phase 5: Delivery
- [ ] Create and update `walkthrough.md` with full details.
- [ ] Review deliverables and confirm 100% feature-completeness.
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| **Pure CSS/JS Popups** | Ensures responsive, lightweight modal loading with absolute zero framework overhead. |
| **Grid-based Accordions** | Makes FAQ section take up 50% less vertical space while displaying high-contrast readable answers. |
| **Horizontal Scrolling Track for Roadmap** | Allows scaling up to 7+ phases without cluttering the page height, maintaining a premium landing page feel. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

