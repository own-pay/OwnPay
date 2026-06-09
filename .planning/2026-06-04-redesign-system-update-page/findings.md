# Findings: System Update Redesign

## Core Discoveries

### 1. Route Map (`config/routes/web.php`)
- `GET /admin/system-update` -> `Admin\SystemUpdateController@index` (Renders dashboard)
- `POST /admin/system-update/check` -> `Admin\SystemUpdateController@check` (Triggers check)
- `POST /admin/system-update/apply` -> `Admin\SystemUpdateController@install` (Performs update)
- `POST /admin/system-update/settings` -> `Admin\SystemUpdateController@settings` (Saves auto-update toggle)

### 2. View Template (`templates/admin/system-update.twig`)
- Renders:
  - Current Version card
  - Latest Available card (shows update button if available, or manual check button)
  - Update History table
  - Settings card (auto-update checkbox)

### 3. Controller Logic (`src/Controller/Admin/SystemUpdateController.php`)
- `index()` loads cache file from `storage/cache/update_check.json` (valid for 1 hour).
- Checks `auto_update` setting from SettingsRepository under `general:auto_update`.
- Loads history from `UpdateHistoryRepository->listFinished()`.

### 4. Database Schema (`op_update_history`)
- Columns: `id`, `from_version`, `to_version`, `status`, `backup_path`, `checksum`, `error`, `started_at`, `completed_at`.
- Status enums: `started`, `backup_created`, `downloaded`, `applied`, `verified`, `completed`, `rolled_back`, `failed`.
- Duration value display in frontend is configured but not dynamically populated in `SystemUpdateController` or `UpdateHistoryRepository` (returns empty / `—`).

### 5. Architectural Style & CSS
- Layout is PSR-4 and auto-escaped Twig base layout.
- Styling resides in `public/assets/css/admin.css` containing dark/light variables (`--op-bg`, `--op-bg-card`, `--op-primary`, etc.).
- There is a pattern of page-specific CSS files inside `public/assets/css/pages/`. We should create a new `public/assets/css/pages/system-update.css` file and link it in the `{% block head %}` of our twig.
