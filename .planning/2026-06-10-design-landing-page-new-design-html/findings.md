# Findings & Decisions

## Requirements

- Redesign ownpay.org landing page into `new_design.html` in `c:\laragon\www\ownpay\ownpay_org_landing_page\`.
- Use the design tokens and system from `DESIGN.md`.
- Match Elite Sponsor section to `donate.php` (horizontal, raw transparent logo, description, "Backed by:" title, compact).
- Add secondary GitHub button right after the waitlist.
- Add Open-Source trust chip.
- Remove extra white space between Hero and Sponsor.
- Replace Deploy Terminal with a 3-step non-terminal visual workflow (01, 02, 03).
- Add "Enterprise Fintech" card.
- Roadmap: Mark Phase 3 Done, add Phase 3.3 (testing and closed beta), add Phase 5 (admin templates, 5.1-5.3 updates), Phase 6 (mobile, 6.1 SDKs), Phase 7 (admin v2.0). Maintain a compact "Nice Map" layout.
- GitHub CTA: "Built by the Community, for the Community." title.
- FAQ Redesign: Redesign to be extremely premium, compact, 2-column.
- Bento Box Sponsors: Insert between "Why we built OwnPay" and "Roadmap". Dynamic focus/dimming in JS, clicking opens sponsor detail popup card.
- Supporter Circles: Re-structure community section into Elite, under $50, and Key Contributors. Avatar circles, name, contribution details, thank you message on hover tooltip.
- Multi-page responsive footer linking donate page and other assets.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| HSL Palette CSS Variables | Cohesion with admin console tokens and modern visual look |
| Inter & Outfit Google Fonts | Inter is highly readable for UI labels; Outfit provides high-tech, modern headings |
| Bento JS logic | Dim other grid elements by adding class `opacity-30 scale-95 transition-all` |
| Hover Tooltips | Saves layout vertical space and offers a clean, premium interaction feel |
