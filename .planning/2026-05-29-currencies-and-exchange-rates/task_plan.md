# Task Plan: Comprehensive World Currencies & Exchange Rate Integration

## Goal
Implement full support for all world currencies (seed ~150 currencies), double-fallback Fawaz Ahmed exchange rate API integration, administrative configuration settings in the settings panel (base currency select, custom API endpoint, manual add currency, manual/auto toggle, manually trigger update), and ensure 100% PHPStan/PHPUnit correctness.

## Current Phase
Phase 1: Research & Planning

## Phases

### Phase 1: Research & Planning
- [x] Research existing CurrencyService, SettingsController, and CurrencyUpdateJob
- [x] Conduct `/grill-me` design interview to align on specifications
- [x] Document research findings in findings.md
- [x] Create and approve implementation plan (`implementation_plan.md` artifact)
- **Status:** complete

### Phase 2: Comprehensive Seed Data
- [x] Prepare comprehensive list of active ISO 4217 world currencies (~150)
- [x] Update `database/seeds/currencies.sql` with the full list and initial rates relative to USD/BDT
- **Status:** complete

### Phase 3: Core Service & Cron Hardening
- [x] Refactor `CurrencyService.php` to handle new base currency switching with immediate API rate fetch
- [x] Refactor `CurrencyUpdateJob.php` to fetch from jsDelivr / Cloudflare Pages fallback / Custom endpoint URL, targeting the active system base currency dynamically (handling lowercase JSON keys from the API)
- **Status:** complete

### Phase 4: Administrative UI & CRUD Controller
- [ ] Update `SettingsController.php` to handle saving custom API endpoints and managing the active list of currencies
- [ ] Expand settings template `templates/admin/settings/index.twig` to add a new "Currencies & Exchange Rates" management section within the payment tab, listing all currencies, status toggles, rate inputs, add manual currency form, and a "Sync Rates Now" trigger button
- **Status:** pending

### Phase 5: Verification & Testing
- [ ] Add unit/integration tests for the new exchange rate sync and CurrencyService conversions
- [ ] Run PHPUnit tests and ensure 100% green passing status
- [ ] Run PHPStan analysis at Level 9 and ensure 100% clean compilation
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Auto-overwrite manual rates | User confirmed manual rates should be overwritten by auto cron updates |
| Immediate fetch on base currency change | User confirmed base currency change should trigger immediate API rate sync |
| jsDelivr + Cloudflare fallback + Custom URL setting | High reliability via CDNs combined with super-admin customization |

## Errors Encountered
| Error | Resolution |
|-------|------------|
