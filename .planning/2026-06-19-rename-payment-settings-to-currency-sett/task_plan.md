# Task Plan: Rename Payment Settings & Brand-wise Enablement

## Goal
Rename the Payment Settings tab to "Currency Settings" at `/admin/settings#tab-payment` and make the currency settings available in the brand-wise view.

## Current Phase
None (All tasks completed)

## Phases

### Phase 1: Controller Modifications
- [x] Add `'payment'` to whitelist of allowed tabs when `isBrandView` is true in `SettingsController::index`
- [x] Add `'payment'` case in `SettingsController::save` when `mid > 0` to persist the brand currency
- **Status:** complete

### Phase 2: Template Modifications
- [x] Add "Currency Settings" tab button to Brand Settings sidebar group in `templates/admin/settings/index.twig`
- [x] Rename "Payment Settings" tab button to "Currency Settings" in the global settings sidebar
- [x] Remove "Default Currency" input from the Identity & Branding tab (`#tab-branding`) when in brand view
- [x] Expose `#tab-payment` panel (renamed to "Currency Settings") to the brand view
- [x] Add brand-specific default currency select element inside `#tab-payment` when in brand view
- [x] Conditionally format the "Currencies & Exchange Rates" card (hide actions, sync buttons, and make inputs readonly if in brand view)
- **Status:** complete

### Phase 3: Language File Modifications
- [x] Update translation key `settings.tab_payment` to "Currency Settings" in config and storage `en.json` files
- **Status:** complete

### Phase 4: Verification & Testing
- [x] Verify template syntax using `composer lint:twig`
- [x] Run PHPStan analysis (`php vendor/bin/phpstan analyse`)
- [x] Run PHPUnit test suite (`vendor/bin/phpunit`)
- **Status:** complete
