# Findings & Decisions - Build OwnPay Landing Page

## Discovery Report

- **Directory structure:**
  - `ownpay_org_landing_page/`
    - `assets/`
      - `img/`
        - `contributors/` (empty)
        - `og/` (empty)
        - `sponsors/`
          - `FlexoHost_logo.webp`
          - `banglahoster.svg`
          - `hostazy.png`
          - `hostsite24.png`
          - `namepart_logo.png`
      - `index.php` (empty/basic placeholder)
    - `index.html` (Existing static landing page with inline Tailwind CSS and JS)
    - `donate.php` (Existing donor payment page using EPS API gateway)
    - `waitlist.php` (Waitlist API handler with local JSON storage)
    - `waitlist.json`
    - `waitlist_log.txt`
    - `waitlist_rl.json`
    - `donations.json`
    - `robots.txt`
    - `sitemap.xml`
- **index.html sections found:**
  - `nav#navbar` (Navigation bar)
  - `section` (Hero section with waitlist subscription form)
  - `section` (Supported By / Trusted By elite sponsors)
  - `section` (Deploy in Minutes deployment steps)
  - `section#how-it-works` (Why we built OwnPay / feature grid)
  - `section` (Sponsors Fueling OwnPay Bento Box grid)
  - `section#roadmap` (Development roadmap horizontal slider)
  - `section` (GitHub Community CTA - Mid-page)
  - `section#faq` (Space-efficient accordion FAQ)
  - `section` (Supporters & Contributors Combined Showcase)
  - `footer` (Global footer links and copyrights)
- **CSS files:** None (currently inline `<style>` and Tailwind CDN in `index.html`).
- **JS files:** None (currently inline `<script>` in `index.html`).
- **Sponsor images found:**
  - `FlexoHost_logo.webp`
  - `banglahoster.svg`
  - `hostazy.png`
  - `hostsite24.png`
  - `namepart_logo.png`
- **Contributor images found:** None (the folder is empty).
- **Existing PHP/config files:**
  - `donate.php`
  - `waitlist.php`
- **Assumptions I am making:**
  - Since Tailwind CSS is forbidden (unless specifically requested, which it isn't here - Phase 2 mandates plain CSS and vanilla JS), we must design a custom design system with custom CSS file and bundle it.
  - The PHP application structure should be raw PHP 8.2+ without external dependencies or Composer, since the execution rules forbid them for the landing page project.
  - SQLite/MySQL: Wait, for database persistence of subscribers, donations, sponsors, contributors, admin users, etc., we need to configure a local database. We'll use MySQL since the core project uses MySQL/PDO and the prompt references MySQL engine (InnoDB, charset utf8mb4) and tables in Phase 1.2.
- **Questions that would block me:**
  - Do we have a MySQL connection setup? Yes, we can read `.env` in the root folder of OwnPay to see the DB credentials, since the landing page is part of the `ownpay` repository. Let's look for existing DB credentials.

## Research Findings

- `waitlist.php` currently stores subscribers directly into a JSON file `waitlist.json` and logs to `waitlist_log.txt` with rate-limiting in `waitlist_rl.json`.
- `donate.php` currently stores donations directly into a JSON file `donations.json` and sponsorships in `sponsorships.json`, with EPS payment gateway integration.
- We need to port this to a real database structure with a secure Admin panel.

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Use MySQL/PDO | Standard db engine requested in Phase 1.2. Read credentials from root `.env` |
| Vanilla CSS + Vanilla JS | Plain CSS/JS requested, Tailwind CDN will be replaced by our custom minified CSS |
| Session-based Auth | Stated in Phase 6 Authentication - HTTPOnly, Secure, SameSite=Strict cookies |
| bcrypt PASSWORD_BCRYPT | Password hashing standard |

## Issues Encountered

| Issue | Resolution |
|-------|------------|
| None | N/A |

## Resources

- `ownpay_org_landing_page/index.html`
- `ownpay_org_landing_page/donate.php`
- `ownpay_org_landing_page/waitlist.php`

## Visual/Browser Findings & Refactoring Details

1. **Light Theme Conversion:** The body background is already `#f8fafc` but the navbar (`rgba(9,10,15,0.8)`), backed-by (`#06070a`), and footer (`#06070a`) sections are dark. We need to convert them to clean white/light-gray backgrounds (`#ffffff`/`#f8fafc`) with matching border adjustments and text colors.
2. **Sponsor Direct Linking:** Instead of modal popup clicking, wrap the sponsors grid images in `<a>` tags targeting the website url directly, set `rel="nofollow noopener noreferrer"`, and remove the grayscale filter from the images.
3. **Supported By Section:** Exclude `FlexoHost` from the elite list in the "Supported by the community" section using conditional loop filtering or db logic, keeping only `Namepart`.
4. **Architecture Flow:** Render the contents of `flow.svg` inline in `home.php` instead of the manually drawn mockup inline SVG.
5. **Timeline Redesign:** Design a professional vertical timeline with left status borders (green for completed, gold for in-progress, gray for planned), a colored timeline track, and modern typography.
6. **FAQ Accordion:** Convert to a space-efficient 2-column layout on desktop to reduce vertical scroll distance.
7. **Footer Upgrades:** Inject Learn, Blog, Developer, Support, and Donate URLs. Ensure the footer background is light and premium.
