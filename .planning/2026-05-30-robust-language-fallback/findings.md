# Findings & Discoveries

## Technical Discoveries
1. **Translation Storage Method:**
   * Baseline translations are defined inside `database/migrations/007_create_languages_table.sql`.
   * Currently, `TranslationService` loads translations purely from the `translations` column of the `op_languages` table in the database.
   * There are no `.json` language files inside `storage/` or the root folder, which makes the translation system reliant purely on the database and highly vulnerable if the storage folder or database is wiped/re-created.

2. **Master `en.json` File Source:**
   * The complete JSON mapping of English translations consists of 115 keys. We will extract this mapping and store it under the version-controlled `config/languages/en.json` file.

3. **Restoration and Fallback Logic:**
   * During installer boot (`InstallerController::finalize()`), we will ensure that `storage/languages/` directory is created and the master `en.json` is copied to it.
   * In `TranslationService::loadTranslationsFromFileOrDb()`, if the requested locale is `en`, we will check if `storage/languages/en.json` exists. If not, we copy it instantly from `config/languages/en.json` to `storage/languages/en.json`.
   * We will load translation keys from `storage/languages/{code}.json` if it exists. If it does not exist (e.g. for a custom uploaded language where the file was deleted), we will load it from the database and write it to `storage/languages/{code}.json` to automatically restore it.
   * When saving, uploading, or creating languages via `TranslationService`, we will write the flattened JSON representation to `storage/languages/{code}.json` in addition to updating the database.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Dual-Write Strategy | Write to both file and database during CRUD actions to keep them perfectly in-sync. |
| Automatic File Recovery | Ensures maximum resilience in production if `storage/` is entirely deleted. |
