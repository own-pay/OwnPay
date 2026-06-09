# Task Plan: Premium Donation Portal Redesign & Counter Dashboard

## Goal
Transform the donation page into a premium, state-of-the-art dark-mode responsive dashboard featuring automated real-time counters, currency-swappable forms, non-monetary sponsorship guides, elite sponsor showcases, and a starring CTA.

## Current Phase
Phase 2

## Phases

### Phase 1: Discovery & Requirements
- [x] Analyze donations.json parser requirements
- [x] Outline HSL Hues and Tailwind configurations
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Design and Planning
- [x] Create refined implementation_plan.md
- [x] Obtain user approval
- **Status:** complete

### Phase 3: Implementation
- [x] Code dynamic JSON aggregations in `donate.php`
- [x] Overhaul CSS styles for light-theme premium responsive look
- [x] Implement Currency Selector and suggested values in JavaScript
- [x] Design non-monetary infrastructure form with sponsorships.json segregation
- [x] Integrate Elite Sponsors showcase grid ("Backed by:" layout)
- [x] Integrate General Sponsors marquee ticker with randomized shuffle
- [x] Add the Star on GitHub CTA button
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Validate compilation syntax (`php -l`)
- [x] Run PHPUnit regression checks
- [x] Manual review of mobile layout
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Read JSON at runtime | Guarantees automatic, instantaneous statistics updates without database dependency. |
| In-browser conversion to BDT | Preserves EPS payment sandbox compatibility while supporting multiple foreign currencies. |
| Equal balanced layout cards | Achieves the premium light-theme spotlight requested by the user. |
| Conditional horizontal marquee scrolling | Only starts sliding animations when more than 3 active sponsors exist, centering static logos otherwise. |
| Full-color branding displays | Removed grayscale filters so that logos retain their original design color schemes at all times. |
| CSS PNG drop-shadow silhouettes | Traces non-transparent image edges with a fine dark drop-shadow outline, keeping white transparent logos 100% visible against white backgrounds without changing the site background. |
| Flex-wrap logo alignments on mobile | Implemented auto-wrapping (`flex-wrap: wrap`) and downscaled dimensions on mobile so that both Elite and General static sponsor logos fit beautifully inside all viewports without horizontal page overflow. |
| Prevent mobile form field auto-zoom | Set standard `16px` font dimensions on mobile input fields to prevent iOS Safari auto-zooming when users interact with the donation form. |
| Premium multi-column navigation footer | Replaced simple footer with a professional corporate grid footer containing project logo, links, responsive column layouts, and a dedicated copyright statement. |
