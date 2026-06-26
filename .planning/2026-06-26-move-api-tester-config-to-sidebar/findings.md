# Findings - Move API Tester Config to Sidebar

## Codebase Analysis
- **File:** `public/api-tester.php` is a single standalone file containing HTML structure, inline CSS/Tailwind configuration, and pure vanilla JS script.
- **Inputs to move:**
  - `baseUrl` (Host)
  - `apiKey` (API Key)
  - `superAdminEmail` (Super Admin Email)
- **Original Layout:**
  - Header handles auth inputs group within `flex flex-col sm:flex-row gap-3 items-center w-full lg:w-2/3 xl:w-3/5`.
  - Sidebar represents a fixed/relative aside block (`#sidebar`) with a container `#sidebarList` for endpoint items.
- **Proposed Layout:**
  - Sidebar will contain the inputs group at the top.
  - Header will only have the logo and the mobile menu trigger.
- **Session Persistence:**
  - Use `sessionStorage` in JavaScript to load/save values.
  - Check `sessionStorage` first on load, falling back to `window.location.origin` for the host URL.
