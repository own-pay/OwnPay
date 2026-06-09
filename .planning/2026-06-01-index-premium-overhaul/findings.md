# Findings - index.html Premium Overhaul

## Existing Layout
- Currently, `index.html` uses Tailwind CSS via CDN.
- The body has a noise overlay background.
- It uses DM Sans for sans-serif and DM Mono for monospace fonts.
- Scroll indicators and scroll animations are present.
- Sponsoring links currently redirect to `https://ownpay.org/donate` (needs mapping to local `donate.php` or relative `/donate` or `./donate.php` paths during local testing).

## Aesthetic Preferences
- Highly premium look, light themes, consistent grid borders.
- Symmetrical layout alignment.
- Popup bento cards should overlay nicely without breaking grid positions.
