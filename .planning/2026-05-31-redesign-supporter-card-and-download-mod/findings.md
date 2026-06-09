# Findings: Redesign Supporter Card and Download Modal

## Codebase Analysis
- **Target File**: `c:\laragon\www\ownpay\public_html\public_html\donate.php`
- **Session Keys Used**:
  - `$_SESSION['completed_donation']` is populated on successful payment callback (lines 239-247) and contains `amount`, `name`, `phone`, `district`, `anonymous`, `unique_id`, and `date_formatted`.
- **Canvas Implementation**:
  - Located inside the `<script>` tag of `donate.php`.
  - Distorts logo due to a hardcoded width/height: `const logoWidth = 160; const logoHeight = 36;` instead of using the dynamic aspect ratio of `logoImg.naturalHeight / logoImg.naturalWidth`.
  - Taglines, website link, and GitHub link are currently jumbled together at the bottom center.
  - Heart icon is missing entirely.
- **Note Collection**:
  - There is a backend POST action `?action=submit_message` (lines 263-332) which takes `message` and `custom_name`, logs it in `donations.json`, and returns JSON if requested via AJAX.

## Approach & Design Patterns
- **Collectible Supporter Badge**: Standardized to 1280x720 (16:9 ratio) using Canvas drawing APIs.
- **GitHub Sponsor Love Heart Outline**: Draw using two Bezier curves with pink glow overlay:
  ```javascript
  ctx.beginPath();
  ctx.moveTo(x, y + 15);
  ctx.bezierCurveTo(x, y - 5, x - 25, y - 5, x - 25, y + 15);
  ctx.bezierCurveTo(x - 25, y + 35, x, y + 55, x, y + 65);
  ctx.bezierCurveTo(x, y + 55, x + 25, y + 35, x + 25, y + 15);
  ctx.bezierCurveTo(x + 25, y - 5, x, y - 5, x, y + 15);
  ctx.strokeStyle = '#ff5a79';
  ctx.lineWidth = 4;
  ctx.stroke();
  ctx.fillStyle = 'rgba(255, 90, 121, 0.15)';
  ctx.fill();
  ```
- **Tier-based Message Selector**: Client-side Javascript checks `completed_donation.amount` to load tier-specific advances points.
