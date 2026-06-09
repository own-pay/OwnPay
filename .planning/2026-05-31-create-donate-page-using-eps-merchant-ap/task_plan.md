# Task Plan: Premium Donation Page via EPS Gateway

## Goal
Create a premium, professional light-themed donation page at `public_html/public_html/donate.php` supporting both normal and anonymous donations, integrating with the EPS Merchant API, providing a thank-you page that collects and logs donation messages to a JSON file, updating the homepage `public_html/public_html/index.html` with real logos, favicons, correct donation URLs, an Elite Sponsor section, and generating a clean, simple Open Graph (OG) image.

## Current Phase
Phase 6: Iteration & Refining

## Phases

### Phase 1: Requirements & Discovery [COMPLETED]
- [x] Analyze `docs\EPSmerchantapiguideline.pdf` and extract endpoints, headers, and hash signature parameters.
- [x] Verify signature BKXUD calculation via test script.
- [x] Inspect existing EPS gateway adaptation in the codebase (`modules/gateways/eps/EpsGateway.php`).
- [x] Copy Logo and Favicon into place inside `public_html/public_html/`.

### Phase 2: Planning & Structure [COMPLETED]
- [x] Design standalone flow in a single `donate.php` script to handle Form, Checkout, Callback, and Thank You views.
- [x] Structure database/sandbox default credentials fallback.
- [x] Plan light theme CSS structure (premium Github-like aesthetics).
- [x] Plan `index.html` logo, favicon, button link, and elite sponsor updates.
- [x] Plan `og-image.png` design layout.

### Phase 3: Implementation [COMPLETED]
- [x] Implement cURL functions for EPS token generation, payment initiation, and payment status verification.
- [x] Implement frontend form view with anonymous checkbox toggling.
- [x] Implement checkout state redirection.
- [x] Implement callback validation and transaction logs.
- [x] Implement thank-you page view and donation message collection.
- [x] Implement JSON file logging for donation messages with user IP.
- [x] Integrate favicon and official logo images inside `index.html`.
- [x] Update all three donation button links in `index.html` to `https://ownpay.org/donate.php`.
- [x] Create a premium Elite Sponsor section for `Namepart` following the Hero grid in `index.html`.
- [x] Generate a clean, simple corporate Open Graph banner image saved to `public_html\public_html\og-image.png`.

### Phase 4: Testing & Verification [COMPLETED]
- [x] Verify form layout is fully responsive and premium.
- [x] Verify anonymous checkbox toggles fields cleanly.
- [x] Verify EPS payload and signatures match PDF guideline specs.
- [x] Verify log recording works and stores data in `public_html/public_html/donations.json`.
- [x] Lint-check `donate.php` using the PHP CLI syntax checker.

### Phase 5: Delivery [COMPLETED]
- [x] Deliver complete clean standalone code files and copy resources.
- [x] Finalize task and obtain user feedback.

### Phase 6: Iteration & Refining [COMPLETED]
- [x] Relocate customizable Supporter Card canvas and inputs to the `thankyou` view alongside the Note form.
- [x] Draw the real horizontal OwnPay logo (`ownpay_logo.png`) inside the canvas card badge.
- [x] Render OwnPay website URL (`ownpay.org`) and GitHub URL (`github.com/own-pay/OwnPay`) elegantly in the card footer.
- [x] Enforce clean extension-less URL mapping (`/donate` instead of `donate.php`) in `.htaccess` and `donate.php`.
- [x] Fix undefined `$uniqueNumber` variable notice in PHP `submit_message` handler.
- [x] Run PHP and JS validation audits to guarantee perfect premium delivery.

### Phase 7: Holographic Collectible Badge & Download Modal [IN PROGRESS]
- [ ] Bypass separate Note page: redirect directly to the final completed page post-payment.
- [ ] Create a premium Pop-up Modal that opens upon clicking "Download Badge", presenting a beautiful thank-you message and collecting the encouragement Note/Custom Name.
- [ ] Implement AJAX Note form submission via fetch to submit notes in the background before downloading.
- [ ] Redesign Supporter Badge Canvas:
  - [ ] Render the horizontal OwnPay brand logo preserving exact aspect ratio.
  - [ ] Position verified unique ID in monospace text directly under the contributor's name in the middle.
  - [ ] Draw a beautiful GitHub-sponsor style rose heart outline icon with light glow.
  - [ ] Render randomized "advances" checklist (e.g. Open Source, Sovereignty, Transparency) dynamically scaled based on BDT amount size.
  - [ ] Display Issued Date (Month and Year) and clean links (`ownpay.org` and `github.com/own-pay/OwnPay`) neatly in bottom corners.
- [ ] Perform live browser compilation check to ensure pristine layout presentation.

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Standalone `donate.php` | Simplifies deployment on a separate web server or GitHub, as requested by the user, without coupling it tightly with OwnPay's core PHP classes. |
| Hybrid Credentials | Read from `.env` first via environment variables, otherwise fall back to customizable local defaults inside the script. |
| Styling | Use vanilla premium CSS with modern styling (Google Fonts 'Inter', clean grids, smooth transitions, high-contrast inputs). |
| Standard Sponsor section | Features elite sponsor `Namepart` beautifully using Tailwind styles that mesh flawlessly with the pre-existing page styles. |
| OG Image Design | Generates a clean blue-themed tech layout with the official typography and tagline to ensure high-end visuals when sharing on social media. |
| Inline Card Rendering | Relocates Supporter Card from final success page to the thank-you Note page to eliminate extra steps and streamline user engagement. |
| Canvas Image Drawing | Loads the real physical logo `ownpay_logo.png` asynchronously into Canvas image object to prevent raw text placeholders. |
| Clean URL Redirects | Employs both .htaccess rewrites and client address bar History scrubbing to prevent exposing the `.php` extension. |
| Download Modal Trigger | Links Note/Encouragement message input to the download badge action, creating a gamified and high-conversion feedback loop. |
| Holographic Collectible | Embellishes the badge with rich digital elements (rose heart, dynamic checklist, verified ID centering) to serve as a status symbol. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Undefined `$uniqueNumber` | Ensure `$uniqueNumber` is defined during note submission prior to storing it in session completed details. |





