# Findings: Currencies and Exchange Rates Support

## Core Findings

1. **Existing Tables:**
   - `op_currencies`: Stores currency records (`code`, `name`, `symbol`, `decimal_places`, `status`). Currently seeded with only 15 currencies.
   - `op_exchange_rates`: Stores exchange rates (`base_currency`, `target_currency`, `rate`, `source`, `updated_at`).
   - `op_system_settings`: Stores global configurations like `default_currency`, `base_currency`, and `exchange_rate_mode`.

2. **CurrencyService (`src/Service/Payment/CurrencyService.php`):**
   - Handles BCMath-based high-precision conversions.
   - Initializes `baseCurrency` by querying settings for `base_currency` (falling back to 'USD').
   - Contains an in-memory cache loaded via `loadCurrencies()`.
   - Offers `upsert()` and `updateExchangeRate()`.

3. **Cron Job (`src/Cron/CurrencyUpdateJob.php`):**
   - Currently hardcoded to fetch USD-based rates from `https://api.exchangerate-api.com/v4/latest/USD` using the core `HttpClient`.
   - Needs to be refactored to fetch from the Fawaz Ahmed API, support any active system base currency, respect the custom API endpoint if configured in the admin panel, and use a robust CDN fallback sequence:
     - Primary CDN: jsDelivr `https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{currencyCode}.json` (lowercase code, e.g. `usd.json`)
     - Fallback: Cloudflare Pages `https://latest.currency-api.pages.dev/v1/currencies/{currencyCode}.json`
     - Custom: If configured, admin custom URL, parsed using fallback logic.

4. **Settings Panel UI (`templates/admin/settings/index.twig`):**
   - Under `#tab-payment`, there is a basic form setting `default_currency`, `exchange_rate_mode`, `payment_expiry_minutes`, `invoice_due_days`, and `auto_approve_payments`.
   - There is currently no UI to view all world currencies, toggle active/inactive status, manually add new currencies, override rates, or manually trigger exchange rate sync.

## Technical Decisions (User Confirmed)

1. **Manual Rates Overwrite:** Manual updates to exchange rates *will* be overwritten by the auto-update cron job unless the auto-update cron job is set to Manual.
2. **Base Currency Sync:** Changing the default base currency will immediately trigger an exchange rate fetch from the API relative to the new base currency.
3. **API Endpoints:** jsDelivr with Cloudflare Pages fallback, plus a field in the settings panel allowing the admin to set a custom API endpoint url.

## Currency Seed Strategy
Seeding ~150-160 active world currencies with correct code, name, symbol, and default decimal places (e.g. JPY=0, BHD=3, USD=2).
Default active: USD, EUR, GBP, INR, BDT, CNY, JPY. Default inactive: All others.
