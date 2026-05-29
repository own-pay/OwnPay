# Findings & Decisions - Level 3 Sandbox Escape Gaps

## Requirements
- Conduct the most advanced "Level 3" deep security audit of OwnPay platform.
- Uncover and secure hidden, extremely deep vulnerabilities that normal tools, automated scanners, or humans miss.
- Implement robust remediations to completely secure the system with zero regressions.

## Research Findings

### FIND-009: [CRITICAL] Language Construct Execution Bypass in Plugin Sandbox Scanner
- **Location**: `src/Plugin/PluginLoader.php` (inside `loadPlugin()`)
- **Description**: The plugin loader scans plugin source code tokens using `token_get_all`. However, the function scanner ONLY inspects identifiers matching `T_STRING` (and similar string/qualified identifiers) followed by an open parenthesis `(`.
  In PHP, language constructs like `eval`, `include`, `include_once`, `require`, and `require_once` do NOT parse as `T_STRING`. They are parsed as their own specialized tokens: `T_EVAL`, `T_INCLUDE`, `T_INCLUDE_ONCE`, `T_REQUIRE`, and `T_REQUIRE_ONCE`.
  As a result, a plugin containing `eval("dangerous code");` or `include "/etc/passwd";` completely bypasses the current scanner and executes successfully!
- **Impact**: Full remote code execution (RCE) and local file inclusion (LFI) sandbox escapes. Attackers can read private env keys, modify files, or execute system commands.
- **Priority**: P0 (Critical - Fix immediately)

### FIND-010: [HIGH] Namespace Import Aliasing Bypass in Plugin Sandbox Scanner
- **Location**: `src/Plugin/PluginLoader.php` (inside `loadPlugin()`)
- **Description**: The scanner matches dangerous functions against the canonical list of blocked functions (e.g. `exec`, `shell_exec`, `passthru`) if they are called as a `T_STRING` followed by `(`.
  However, PHP allows functions to be imported and renamed using `use function <dangerous> as <alias>;`.
  For example, if a plugin does:
  ```php
  use function exec as safe_name;
  safe_name("whoami");
  ```
  The scanner will not trigger on the import statement because `exec` is not followed by `(`, and it will not trigger on the function call `safe_name()` because `safe_name` is not in the list of dangerous functions.
- **Impact**: Arbitrary system-level execution via imported alias function names.
- **Priority**: P1 (High - Fix immediately)

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| **Block Language Construct Tokens** | Statically scan and throw a RuntimeException if any `T_EVAL`, `T_INCLUDE`, `T_INCLUDE_ONCE`, `T_REQUIRE`, or `T_REQUIRE_ONCE` token is encountered in any PHP file in the plugin directory. |
| **Verify Namespace Import Statements (`T_USE`)** | Scan forward inside every `T_USE` statement block (until a semicolon, comma, or open brace) and reject the plugin if any identifier matches a dangerous function (e.g. `exec`) or a restricted class/reference prefix (like `Reflection` or `PDO`). |

## Resources
- [PluginLoader.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginLoader.php)
- [PluginSandbox.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginSandbox.php)
