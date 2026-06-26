# Findings - Modern Custom Alert Dialog for API Tester

## Codebase Analysis
Four occurrences of native `alert` exist in `public/api-tester.php`:
1. JSON body formatting catch block (Line 499): `alert('Invalid JSON formatting.');`
2. API Key required warning for authed endpoints (Line 530): `alert('An API Key / Bearer JWT is required to query authenticated endpoints.');`
3. Missing dynamic path params (Line 548): `alert('Please provide values for all dynamic path variables.');`
4. Request body JSON parse errors during request prepare (Line 579): `alert('Malformed JSON syntax in body.');`

## UI/UX Design Intent
- Overlay: full-screen absolute position with `bg-slate-900/60` and `backdrop-blur-xs` (low blur).
- Card: white base, Plus Jakarta Sans font, rounded-3xl (matches other panels), premium shadow, scaling/opacity transitions.
- Types of Icons:
  - Error: rose theme with exclamation/cross icon.
  - Warning: amber theme with warning icon.
