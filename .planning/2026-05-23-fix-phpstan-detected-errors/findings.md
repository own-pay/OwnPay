# Research & Findings - PHPStan Level 6 Errors

We analysed all 31 PHPStan level 6 errors related to missing iterable value types (`missingType.iterableValue`). Below is the complete catalog of affected files, classes, methods, and their exact current/target signatures.

## 1. Core Component (12 Errors)

### `src/Core/Database.php` (9 Errors)
- **Line 151**: `fetchAll`
  - *Current*: `public function fetchAll(string $sql, array $params = []): array`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 164**: `fetchOne`
  - *Current*: `public function fetchOne(string $sql, array $params = []): ?array`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 179**: `fetchColumn`
  - *Current*: `public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 194**: `execute`
  - *Current*: `public function execute(string $sql, array $params = []): PDOStatement`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 273**: `insert`
  - *Current*: `public function insert(string $sql, array $params = []): string`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 286**: `update`
  - *Current*: `public function update(string $sql, array $params = []): int`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 298**: `delete`
  - *Current*: `public function delete(string $sql, array $params = []): int`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 373**: `exists`
  - *Current*: `public function exists(string $table, string $where, array $params = []): bool`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`
- **Line 392**: `count`
  - *Current*: `public function count(string $table, string $where = '1=1', array $params = []): int`
  - *Fix*: Annotate `$params` as `array<string|int, mixed>`

### `src/Core/FormattingHelper.php` (3 Errors)
- **Line 102**: `resolveModuleLanguage`
  - *Current*: `public function resolveModuleLanguage(string $brandLanguage, array $supportedLanguages, ?string $uiLanguage = null): string`
  - *Fix*: Annotate `$supportedLanguages` as `array<string, mixed>`
- **Line 122**: `buildLangArray` (parameter and return type)
  - *Current*: `public function buildLangArray(array $langText, ?string $language = 'en'): array`
  - *Fix*: Annotate `$langText` as `array<string, array<string, string>>` and return type as `array<string, string>`

---

## 2. Controller Component (13 Errors)

### `src/Controller/BaseController.php` (2 Errors)
- **Line 88**: `renderFragment`
  - *Current*: `protected function renderFragment(string $template, array $data = []): Response`
  - *Fix*: Annotate `$data` as `array<string, mixed>`
- **Line 115**: `jsonError`
  - *Current*: `protected function jsonError(string $message, int $status = 400, array $errors = []): Response`
  - *Fix*: Annotate `$errors` as `array<string, mixed>|array<int, mixed>`

### `src/Controller/Admin/BrandController.php` (3 Errors)
- **Line 348**: `handleBrandUploadsAndSettings` (two parameters and return type)
  - *Current*: `private function handleBrandUploadsAndSettings(Request $req, int $merchantId, array $data, ?string $existingLogoPath = null, array $existingSettings = []): array`
  - *Fix*: Annotate `$data` as `array<string, mixed>`, `$existingSettings` as `array<string, mixed>`, and return type as `array<string, mixed>` or specific shape.

### `src/Controller/Admin/StaffController.php` (1 Error)
- **Line 275**: `getRolesForMerchant`
  - *Current*: `private function getRolesForMerchant(int $merchantId): array`
  - *Fix*: Annotate return type as `array<int, array<string, mixed>>`

### `src/Controller/Api/TransactionController.php` (2 Errors)
- **Line 116**: `safeFields` (parameter and return type)
  - *Current*: `private function safeFields(array $t): array`
  - *Fix*: Annotate `$t` parameter and return type as `array<string, mixed>`

### `src/Controller/Checkout/PaymentIntentCheckoutController.php` (2 Errors)
- **Line 808**: `renderStatus`
  - *Current*: `private function renderStatus(string $ref, string $status, ?array $intent = null): Response`
  - *Fix*: Annotate `$intent` as `array<string, mixed>|null`
- **Line 897**: `loadBrand`
  - *Current*: `private function loadBrand(int $mid): array`
  - *Fix*: Annotate return type as `array<string, mixed>`

### `src/Controller/Install/InstallerController.php` (2 Errors)
- **Line 404**: `checkRequirements`
  - *Current*: `private function checkRequirements(): array`
  - *Fix*: Annotate return type as `array<int, array{name: string, required: string, current: string, ok: bool}>`
- **Line 447**: `renderPhpTemplate`
  - *Current*: `private function renderPhpTemplate(string $template, array $data): string`
  - *Fix*: Annotate `$data` as `array<string, mixed>`

### `src/Controller/Webhook/UnifiedWebhookController.php` (1 Error)
- **Line 207**: `logAttempt`
  - *Current*: `private function logAttempt(string $gateway, string $reason, Request $req, array $context = []): void`
  - *Fix*: Annotate `$context` as `array<string, mixed>`

---

## 3. Plugin, Repository & Service Components (6 Errors)

### `src/Plugin/PluginLoader.php` (2 Errors)
- **Line 169**: `loadPlugin`
  - *Current*: `private function loadPlugin(array $pluginData): void`
  - *Fix*: Annotate `$pluginData` as `array<string, mixed>`
- **Line 343**: `resolvePluginPath`
  - *Current*: `private function resolvePluginPath(array $pluginData): string`
  - *Fix*: Annotate `$pluginData` as `array<string, mixed>`

### `src/Repository/FeeRuleRepository.php` (1 Error)
- **Line 31**: `resolveActiveRule`
  - *Current*: `public function resolveActiveRule(int $merchantId, string $gatewaySlug, string $currency): ?array`
  - *Fix*: Annotate return type as `array<string, mixed>|null`

### `src/Service/Payment/LedgerService.php` (2 Errors)
- **Line 56**: `postEntries`
  - *Current*: `public function postEntries(int $merchantId, string $eventType, string $currency, array $entries, string $referenceType, string $referenceId, ?string $description = null): void`
  - *Fix*: Annotate `$entries` as `array<int, array{account: string, type: 'debit'|'credit', amount: string}>`
- **Line 215**: `entries`
  - *Current*: `public function entries(int $merchantId, int $page = 1, int $perPage = 50): array`
  - *Fix*: Annotate return type as `array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}`

### `src/Update/UpdateService.php` (1 Error)
- **Line 362**: `fetchManifest`
  - *Current*: `protected function fetchManifest(): array`
  - *Fix*: Annotate return type as `array<string, mixed>`
