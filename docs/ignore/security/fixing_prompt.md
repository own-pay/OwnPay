# Jules Agent Task: Fix All Semgrep Security Findings
## Repository: `ownpay` | Branch: `main`

---

## ROLE & OBJECTIVE

You are an expert PHP security engineer. Your sole task is to fix **all 125 Semgrep security findings** listed below in the `ownpay` repository. You must fix every single vulnerability without missing any, without breaking existing functionality, and without introducing new bugs.

---

## ABSOLUTE RULES — NEVER VIOLATE THESE

1. **DO NOT skip any finding.** Fix every one of the 125 findings listed below.
2. **DO NOT refactor unrelated code.** Only touch the exact lines mentioned in each finding.
3. **DO NOT change business logic.** Security fixes must be purely additive — sanitize/validate/escape inputs; do not alter how the application works.
4. **DO NOT remove functionality.** If a function uses `exec()`, make it safe — do not delete it.
5. **DO NOT use broad suppressions** (e.g., `// nosemgrep`, `@suppress`, `error_reporting(0)`). Fix the root cause.
6. **DO NOT introduce new vulnerabilities** while fixing old ones.
7. **ALWAYS read the full function/method context** before making a fix. Never fix a line in isolation without understanding its surrounding code.
8. **ALWAYS preserve the original code indentation and code style.**
9. **ALWAYS test that the fix is syntactically valid PHP** — no parse errors allowed.
10. **After all fixes, run `semgrep --config=auto` on the changed files** and confirm zero findings remain.

---

## VULNERABILITY CATEGORIES & FIX STRATEGY

Apply these exact fix strategies for each vulnerability type:

### 1. XSS — `taint-unsafe-echo-tag` (92 findings)
**Pattern:** `<?= $_REQUEST['key'] ?>` or `<?= $var ?>` where `$var` comes from `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`

**Fix Strategy:**
```php
// BEFORE (vulnerable):
<?= $_REQUEST['gateway_type'] ?>
<?= $someUserVar ?>

// AFTER (fixed):
<?= htmlspecialchars($_REQUEST['gateway_type'], ENT_QUOTES, 'UTF-8') ?>
<?= htmlspecialchars($someUserVar, ENT_QUOTES, 'UTF-8') ?>
```
- Use `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` for ALL user-controlled values echoed into HTML.
- Do NOT use `htmlentities()` — use `htmlspecialchars()` with `ENT_QUOTES`.
- If the value is expected to be an integer, cast it: `(int) $_REQUEST['id']` instead of wrapping in `htmlspecialchars`.
- If the value is used in a `value=""` HTML attribute, `htmlspecialchars` with `ENT_QUOTES` is mandatory.

---

### 2. PATH TRAVERSAL — `tainted-path-traversal` + `laravel-path-traversal` (12 findings)
**Pattern:** User input flows into `include`, `require`, `include_once`, `require_once`, or `Storage::path()` / file path construction.

**Affected files:**
- `app/admin/dashboard/addons/edit.php` L15
- `app/admin/dashboard/gateways/edit.php` L31
- `app/admin/dashboard/settings/themes-setting.php` L11
- `app/core/adapter.php` L498, L500
- `index.php` L201

**Fix Strategy — Use an allowlist:**
```php
// BEFORE (vulnerable):
$file = $_REQUEST['addon'];
include($file . '.php');

// AFTER (fixed):
$file = $_REQUEST['addon'];
// Strip any path traversal sequences
$file = basename($file); // Remove directory components
$file = preg_replace('/[^a-zA-Z0-9_\-]/', '', $file); // Only allow safe chars

// Allowlist: define permitted values explicitly
$ALLOWED_FILES = ['addon1', 'addon2', 'gateway-stripe', /* add all valid values */];
if (!in_array($file, $ALLOWED_FILES, true)) {
    http_response_code(400);
    exit('Invalid file reference.');
}

// Use a fixed base directory with realpath validation
$basePath = realpath(__DIR__ . '/allowed_dir/');
$fullPath = realpath($basePath . '/' . $file . '.php');

if ($fullPath === false || strpos($fullPath, $basePath) !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

include($fullPath);
```

**For `app/core/adapter.php` specifically:**
- Read the adapter's `include`/`require` logic at L498 and L500.
- The allowlist must be populated with all legitimate module/adapter names that the application actually uses.
- Use `realpath()` + prefix check pattern above.

---

### 3. TAINTED FILENAME — `tainted-filename` (6 findings)
**Pattern:** User input used directly to construct a filename passed to file functions.

**Affected files:**
- `app/admin/dashboard/addons/edit.php` L14
- `app/admin/dashboard/gateways/edit.php` L30
- `app/admin/dashboard/settings/themes-setting.php` L10
- `app/core/adapter.php` L497, L499
- `index.php` L200

**Fix Strategy:**
```php
// BEFORE (vulnerable):
$filename = $_REQUEST['name'];

// AFTER (fixed):
$filename = $_REQUEST['name'];
$filename = basename($filename); // Strip path components
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename); // Sanitize to safe chars only
$filename = ltrim($filename, '.'); // Prevent hidden files
if (empty($filename)) {
    http_response_code(400);
    exit('Invalid filename.');
}
```

---

### 4. TAINTED OBJECT INSTANTIATION — `tainted-object-instantiation` (3 findings)
**Pattern:** `new $className()` where `$className` comes from user input — allows instantiation of arbitrary classes (RCE risk).

**Affected files:**
- `app/admin/dashboard/addons/edit.php` L19
- `app/admin/dashboard/gateways/edit.php` L35
- `app/admin/dashboard/settings/themes-setting.php` L13

**Fix Strategy — Strict class allowlist:**
```php
// BEFORE (vulnerable):
$className = $_REQUEST['class'];
$obj = new $className();

// AFTER (fixed):
$className = $_REQUEST['class'];

// Define ONLY the classes that are legitimately instantiated here
$ALLOWED_CLASSES = [
    'AddonFoo',
    'AddonBar',
    // Add all valid class names this code path legitimately uses
];

if (!in_array($className, $ALLOWED_CLASSES, true)) {
    http_response_code(400);
    exit('Invalid class reference.');
}

// Additionally verify the class actually exists
if (!class_exists($className)) {
    http_response_code(400);
    exit('Class not found.');
}

$obj = new $className();
```

---

### 5. COMMAND INJECTION — `exec-use` (1 finding)
**Pattern:** `exec()` called with a non-constant command string that includes user-controlled data.

**Affected file:** `src/Service/UpdaterService.php` L244

**Fix Strategy:**
```php
// BEFORE (vulnerable):
exec($command);

// AFTER (fixed):
// Option A: Use escapeshellarg() / escapeshellcmd() if the command structure must remain
$safeArg = escapeshellarg($userProvidedArgument);
$command = 'some-fixed-binary ' . $safeArg;
exec($command, $output, $returnCode);
if ($returnCode !== 0) {
    throw new \RuntimeException('Command failed');
}

// Option B: If the command is fully constructable from known-safe parts, use proc_open
// with an array of arguments (never shell-interpolated):
$process = proc_open(
    ['fixed-binary', $arg1, $arg2], // Array form: no shell expansion
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
```
- Read the full `UpdaterService.php` to understand what command is being executed.
- If the command is entirely internal (no user input should reach it), trace how user data flows into it and cut off that data flow instead.

---

### 6. UNSAFE UNLINK — `unlink-use` (11 findings)
**Pattern:** `unlink($userInput)` or `unlink($pathBuiltFromUserInput)` — user controls which file gets deleted.

**Affected files:**
- `src/Service/PluginManager.php` L237
- `src/Service/UpdaterService.php` L103, L234, L256
- `src/Service/ImageService.php` L113
- Multiple others — check the full list below

**Fix Strategy:**
```php
// BEFORE (vulnerable):
unlink($filePath); // where $filePath derives from user input

// AFTER (fixed):
// Step 1: Resolve real path
$allowedBaseDir = realpath('/var/www/html/uploads/'); // Use the actual safe directory
$resolvedPath   = realpath($filePath);

// Step 2: Validate path is within allowed directory
if ($resolvedPath === false || strpos($resolvedPath, $allowedBaseDir . DIRECTORY_SEPARATOR) !== 0) {
    throw new \InvalidArgumentException('Attempt to delete file outside allowed directory.');
}

// Step 3: Validate file exists and is a regular file (not a symlink, not a directory)
if (!is_file($resolvedPath) || is_link($resolvedPath)) {
    throw new \InvalidArgumentException('Target is not a regular file.');
}

// Step 4: Safe to delete
unlink($resolvedPath);
```
- For each `unlink()` call, identify the **actual intended base directory** from context (e.g., uploads dir, plugin dir) and use that as `$allowedBaseDir`.

---

## COMPLETE FILE-BY-FILE FIX LIST

Fix every file below. For each file, fix ALL listed line numbers.

### HIGH SEVERITY FILES

| File | Line | Rule | Fix |
|------|------|------|-----|
| `src/Service/UpdaterService.php` | L244 | exec-use | escapeshellarg / proc_open |
| `app/admin/dashboard/addons/edit.php` | L15 | tainted-path-traversal | allowlist + realpath |
| `app/admin/dashboard/gateways/edit.php` | L31 | tainted-path-traversal | allowlist + realpath |
| `app/admin/dashboard/settings/themes-setting.php` | L11 | tainted-path-traversal | allowlist + realpath |
| `app/core/adapter.php` | L498 | tainted-path-traversal | allowlist + realpath |
| `app/core/adapter.php` | L500 | tainted-path-traversal | allowlist + realpath |
| `index.php` | L201 | tainted-path-traversal | allowlist + realpath |

### MEDIUM SEVERITY FILES

#### XSS Fixes (`htmlspecialchars` with `ENT_QUOTES, 'UTF-8'`)

| File | Lines with XSS | Count |
|------|----------------|-------|
| `app/admin/dashboard/addons/edit.php` | L40 + others | ~2 |
| `app/admin/dashboard/brands/edit.php` | multiple | 2 |
| `app/admin/dashboard/devices/balance-verification.php` | multiple | 3 |
| `app/admin/dashboard/gateways/edit.php` | L96, L96, L100, L100, L104, L104, L108, L112, L112, L116, L116, L120, L120, L124, L128, L132, L136, L140, L144 | ~19 |
| `app/admin/dashboard/invoice/edit.php` | multiple | 16 |
| `app/admin/dashboard/payment-link/edit.php` | multiple | 8 |
| `app/admin/dashboard/settings/themes-setting.php` | multiple | ~2 |
| `app/admin/dashboard/staff-management/edit-permissions.php` | multiple | 2 |
| `app/admin/dashboard/staff-management/edit.php` | multiple | 5 |
| `app/admin/dashboard/staff-management/permissions-list.php` | multiple | 2 |
| `app/admin/dashboard/transaction/edit.php` | multiple | 27 |
| `app/install/index.php` | 1 line | 1 |
| `app/modules/themes/own-pay/gateway.php` | 1 line | 1 |

#### Path Traversal / Filename Injection Fixes

| File | Line | Rule |
|------|------|------|
| `app/admin/dashboard/addons/edit.php` | L14 | tainted-filename |
| `app/admin/dashboard/addons/edit.php` | L15 | laravel-path-traversal |
| `app/admin/dashboard/addons/edit.php` | L19 | tainted-object-instantiation |
| `app/admin/dashboard/gateways/edit.php` | L30 | tainted-filename |
| `app/admin/dashboard/gateways/edit.php` | L31 | laravel-path-traversal |
| `app/admin/dashboard/gateways/edit.php` | L35 | tainted-object-instantiation |
| `app/admin/dashboard/settings/themes-setting.php` | L10 | tainted-filename |
| `app/admin/dashboard/settings/themes-setting.php` | L11 | laravel-path-traversal |
| `app/admin/dashboard/settings/themes-setting.php` | L13 | tainted-object-instantiation |
| `app/core/adapter.php` | L497 | tainted-filename |
| `app/core/adapter.php` | L498 | laravel-path-traversal |
| `app/core/adapter.php` | L499 | tainted-filename |
| `app/core/adapter.php` | L500 | laravel-path-traversal |
| `index.php` | L200 | tainted-filename |
| `index.php` | L201 | laravel-path-traversal |

#### Unlink Fixes

| File | Line | Rule |
|------|------|------|
| `src/Service/PluginManager.php` | L237 | unlink-use |
| `src/Service/UpdaterService.php` | L103 | unlink-use |
| `src/Service/UpdaterService.php` | L234 | unlink-use |
| `src/Service/UpdaterService.php` | L256 | unlink-use |
| `src/Service/ImageService.php` | L113 | unlink-use |
| `src/Controller/SystemUpdateController.php` | (check file) | unlink-use |
| `src/Http/Controller/AdminUpdateController.php` | (check file) | unlink-use |
| `src/Plugin/PluginInstaller.php` | (check file) | unlink-use |
| `src/Plugin/PluginRegistry.php` | (check file) | unlink-use |
| + 2 more unlink findings | (see Semgrep report) | unlink-use |

---

## EXECUTION PLAN — FOLLOW THIS EXACT ORDER

### Phase 1: Read First, Fix Second
Before making any edit, read the **complete** content of each file. Never fix a line without understanding what the whole function does.

### Phase 2: Fix High Severity First (7 findings)
1. `src/Service/UpdaterService.php` — Fix L244 exec injection
2. `app/admin/dashboard/addons/edit.php` — Fix L15 path traversal
3. `app/admin/dashboard/gateways/edit.php` — Fix L31 path traversal
4. `app/admin/dashboard/settings/themes-setting.php` — Fix L11 path traversal
5. `app/core/adapter.php` — Fix L498 and L500 path traversal
6. `index.php` — Fix L201 path traversal

### Phase 3: Fix Object Instantiation (3 findings)
7. `app/admin/dashboard/addons/edit.php` L19
8. `app/admin/dashboard/gateways/edit.php` L35
9. `app/admin/dashboard/settings/themes-setting.php` L13

### Phase 4: Fix Filename Injection (6 findings)
10. All 6 tainted-filename findings (see table above)

### Phase 5: Fix Unlink (11 findings)
11. All 11 unlink-use findings in Service and Controller classes

### Phase 6: Fix XSS (92 findings)
12. Fix all `<?= $unsafeVar ?>` patterns file by file, starting with highest-impact files:
    - `app/admin/dashboard/transaction/edit.php` (27 findings)
    - `app/admin/dashboard/gateways/edit.php` (19 findings)
    - `app/admin/dashboard/invoice/edit.php` (16 findings)
    - `app/admin/dashboard/payment-link/edit.php` (8 findings)
    - `app/admin/dashboard/staff-management/edit.php` (5 findings)
    - All remaining files

### Phase 7: Verify
13. Run `semgrep --config=auto` on all changed files.
14. Confirm **0 findings** remain.
15. If any findings remain, fix them before completing.

---

## COMMIT INSTRUCTIONS

Create a single commit (or one commit per phase if the agent supports it) with this message format:

```
fix(security): remediate all 125 Semgrep findings

- Fix 92 XSS vulnerabilities via htmlspecialchars(ENT_QUOTES, UTF-8)
- Fix 12 path traversal issues with allowlist + realpath validation
- Fix 6 tainted filename injections with basename + regex sanitization
- Fix 3 tainted object instantiation with class allowlists
- Fix 1 command injection in UpdaterService with escapeshellarg
- Fix 11 unsafe unlink() calls with base directory validation

Refs: Semgrep findings 765636658–765636776
```

---

## PROHIBITED ACTIONS

- ❌ Do NOT delete any PHP file
- ❌ Do NOT rename any file or function
- ❌ Do NOT add new dependencies or composer packages
- ❌ Do NOT change database queries or schema
- ❌ Do NOT add logging that exposes sensitive data
- ❌ Do NOT use `strip_tags()` alone as XSS prevention — it is insufficient
- ❌ Do NOT use `addslashes()` as XSS prevention
- ❌ Do NOT use `mysql_real_escape_string()` as XSS prevention
- ❌ Do NOT suppress errors to hide the vulnerability
- ❌ Do NOT commit if any Semgrep finding still exists

---

## SUCCESS CRITERIA

The task is complete ONLY when ALL of the following are true:

- [ ] All 125 Semgrep findings are resolved (verified by re-running `semgrep --config=auto`)
- [ ] No new Semgrep findings introduced
- [ ] No PHP parse errors in any modified file (`php -l <file>` returns no errors)
- [ ] All modified files are committed to the `main` branch
- [ ] The application's existing test suite passes (if tests exist)

**Do not mark the task as done until all checkboxes above are confirmed.**
