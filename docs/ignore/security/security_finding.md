# Semgrep Code Combined Findings

**Repository:** `ownpay`  
**Branch:** `refs/heads/main`  
**Report Date:** 2026-04-27  
**Total Findings:** 125

---

## Summary

| Severity | Count |
|----------|-------|
| 🔴 High | 7 |
| 🟡 Medium | 118 |

### Top Rules

| Rule | Occurrences |
|------|------------|
| `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag` | 92 |
| `php.lang.security.unlink-use.unlink-use` | 11 |
| `php.lang.security.tainted-path-traversal.tainted-path-traversal` | 6 |
| `php.lang.security.injection.tainted-filename.tainted-filename` | 6 |
| `php.laravel.security.laravel-path-traversal.laravel-path-traversal` | 6 |
| `php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation` | 3 |
| `php.lang.security.exec-use.exec-use` | 1 |

### File Risk Tags

| Tag | Count |
|-----|-------|
| *(none)* | 91 |
| Payments | 23 |
| Superuser | 11 |

---

## Findings

### 🔴 High Severity (7 findings)

#### `php.lang.security.exec-use.exec-use`
**ID:** 765636776  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Service/UpdaterService.php#L244](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Service/UpdaterService.php#L244)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636776)  

> Executing non-constant commands. This can lead to command injection.

#### `php.lang.security.tainted-path-traversal.tainted-path-traversal`
**ID:** 765636775  
**Confidence:** 🔵 High  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/addons/edit.php#L15](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/addons/edit.php#L15)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636775)  

> Detected user input going into a php include or require command, which can lead to path traversal and sensitive data being exposed. These commands can also lead to code execution. Instead, allowlist files that the user can access or rigorously validate user input.

#### `php.lang.security.tainted-path-traversal.tainted-path-traversal`
**ID:** 765636774  
**Confidence:** 🔵 High  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L31](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L31)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636774)  

> Detected user input going into a php include or require command, which can lead to path traversal and sensitive data being exposed. These commands can also lead to code execution. Instead, allowlist files that the user can access or rigorously validate user input.

#### `php.lang.security.tainted-path-traversal.tainted-path-traversal`
**ID:** 765636773  
**Confidence:** 🔵 High  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/settings/themes-setting.php#L11](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/settings/themes-setting.php#L11)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636773)  

> Detected user input going into a php include or require command, which can lead to path traversal and sensitive data being exposed. These commands can also lead to code execution. Instead, allowlist files that the user can access or rigorously validate user input.

#### `php.lang.security.tainted-path-traversal.tainted-path-traversal`
**ID:** 765636772  
**Confidence:** 🔵 High  
**Status:** Open  
**File:** [app/core/adapter.php#L498](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L498)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636772)  

> Detected user input going into a php include or require command, which can lead to path traversal and sensitive data being exposed. These commands can also lead to code execution. Instead, allowlist files that the user can access or rigorously validate user input.

#### `php.lang.security.tainted-path-traversal.tainted-path-traversal`
**ID:** 765636771  
**Confidence:** 🔵 High  
**Status:** Open  
**File:** [app/core/adapter.php#L500](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L500)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636771)  

> Detected user input going into a php include or require command, which can lead to path traversal and sensitive data being exposed. These commands can also lead to code execution. Instead, allowlist files that the user can access or rigorously validate user input.

#### `php.lang.security.tainted-path-traversal.tainted-path-traversal`
**ID:** 765636770  
**Confidence:** 🔵 High  
**Status:** Open  
**File:** [index.php#L201](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/index.php#L201)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636770)  

> Detected user input going into a php include or require command, which can lead to path traversal and sensitive data being exposed. These commands can also lead to code execution. Instead, allowlist files that the user can access or rigorously validate user input.

### 🟡 Medium Severity (118 findings)

#### `php.lang.security.injection.tainted-filename.tainted-filename`
**ID:** 765636769  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/addons/edit.php#L14](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/addons/edit.php#L14)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636769)  

> File name based on user input risks server-side request forgery.

#### `php.lang.security.injection.tainted-filename.tainted-filename`
**ID:** 765636768  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L30](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L30)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636768)  

> File name based on user input risks server-side request forgery.

#### `php.lang.security.injection.tainted-filename.tainted-filename`
**ID:** 765636767  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/settings/themes-setting.php#L10](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/settings/themes-setting.php#L10)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636767)  

> File name based on user input risks server-side request forgery.

#### `php.lang.security.injection.tainted-filename.tainted-filename`
**ID:** 765636766  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L497](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L497)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636766)  

> File name based on user input risks server-side request forgery.

#### `php.lang.security.injection.tainted-filename.tainted-filename`
**ID:** 765636765  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L499](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L499)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636765)  

> File name based on user input risks server-side request forgery.

#### `php.lang.security.injection.tainted-filename.tainted-filename`
**ID:** 765636764  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [index.php#L200](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/index.php#L200)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636764)  

> File name based on user input risks server-side request forgery.

#### `php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation`
**ID:** 765636763  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/addons/edit.php#L19](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/addons/edit.php#L19)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636763)  

> <- A new object is created where the class name is based on user input. This could lead to remote code execution, as it allows to instantiate any class in the application.

#### `php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation`
**ID:** 765636762  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L35](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L35)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636762)  

> <- A new object is created where the class name is based on user input. This could lead to remote code execution, as it allows to instantiate any class in the application.

#### `php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation`
**ID:** 765636761  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/settings/themes-setting.php#L13](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/settings/themes-setting.php#L13)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636761)  

> <- A new object is created where the class name is based on user input. This could lead to remote code execution, as it allows to instantiate any class in the application.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636760  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/addons/edit.php#L40](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/addons/edit.php#L40)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636760)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636759  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/addons/edit.php#L47](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/addons/edit.php#L47)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636759)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636758  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/brands/edit.php#L31](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/brands/edit.php#L31)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636758)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636757  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/brands/edit.php#L36](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/brands/edit.php#L36)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636757)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636756  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/devices/balance-verification.php#L253](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/devices/balance-verification.php#L253)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636756)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636755  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/devices/balance-verification.php#L338](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/devices/balance-verification.php#L338)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636755)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636754  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/devices/balance-verification.php#L364](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/devices/balance-verification.php#L364)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636754)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636753  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L78](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L78)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636753)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636752  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L96](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L96)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636752)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636751  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L96](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L96)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636751)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636750  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L100](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L100)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636750)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636749  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L100](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L100)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636749)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636748  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L104](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L104)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636748)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636747  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L104](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L104)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636747)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636746  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L108](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L108)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636746)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636745  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L112](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L112)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636745)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636744  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L112](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L112)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636744)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636743  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L116](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L116)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636743)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636742  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L152](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L152)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636742)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636741  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L162](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L162)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636741)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636740  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L163](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L163)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636740)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636739  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L164](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L164)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636739)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636738  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L165](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L165)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636738)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636737  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L179](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L179)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636737)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636736  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L180](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L180)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636736)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636735  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L216](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L216)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636735)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636734  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L44](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L44)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636734)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636733  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L45](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L45)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636733)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636732  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L45](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L45)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636732)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636731  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L52](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L52)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636731)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636730  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L64](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L64)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636730)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636729  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L86](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L86)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636729)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636728  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L107](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L107)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636728)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636727  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L110](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L110)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636727)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636726  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L118](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L118)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636726)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636725  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L123](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L123)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636725)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636724  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L127](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L127)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636724)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636723  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L131](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L131)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636723)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636722  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L135](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L135)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636722)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636721  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L153](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L153)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636721)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636720  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L164](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L164)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636720)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636719  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/invoice/edit.php#L183](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/invoice/edit.php#L183)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636719)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636718  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L46](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L46)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636718)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636717  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L47](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L47)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636717)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636716  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L47](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L47)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636716)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636715  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L54](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L54)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636715)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636714  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L90](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L90)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636714)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636713  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L95](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L95)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636713)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636712  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L114](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L114)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636712)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636711  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/payment-link/edit.php#L118](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/payment-link/edit.php#L118)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636711)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636710  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/settings/themes-setting.php#L48](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/settings/themes-setting.php#L48)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636710)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636709  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit-permissions.php#L16](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit-permissions.php#L16)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636709)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636708  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit-permissions.php#L22](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit-permissions.php#L22)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636708)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636707  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit.php#L32](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit.php#L32)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636707)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636706  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit.php#L39](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit.php#L39)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636706)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636705  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit.php#L40](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit.php#L40)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636705)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636704  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit.php#L41](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit.php#L41)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636704)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636703  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/edit.php#L56](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/edit.php#L56)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636703)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636702  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/permissions-list.php#L70](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/permissions-list.php#L70)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636702)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636701  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/staff-management/permissions-list.php#L96](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/staff-management/permissions-list.php#L96)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636701)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636700  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L69](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L69)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636700)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636699  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L69](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L69)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636699)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636698  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L70](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L70)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636698)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636697  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L70](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L70)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636697)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636696  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L81](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L81)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636696)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636695  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L85](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L85)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636695)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636694  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L89](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L89)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636694)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636693  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L114](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L114)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636693)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636692  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L118](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L118)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636692)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636691  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L122](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L122)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636691)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636690  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L127](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L127)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636690)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636689  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L132](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L132)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636689)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636688  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L143](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L143)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636688)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636687  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L147](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L147)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636687)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636686  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L151](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L151)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636686)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636685  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L155](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L155)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636685)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636684  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L159](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L159)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636684)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636683  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L163](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L163)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636683)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636682  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L176](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L176)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636682)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636681  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L180](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L180)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636681)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636680  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L184](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L184)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636680)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636679  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L198](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L198)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636679)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636678  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L199](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L199)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636678)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636677  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L242](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L242)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636677)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636676  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L246](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L246)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636676)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636675  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L294](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L294)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636675)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636674  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/admin/dashboard/transaction/edit.php#L310](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/transaction/edit.php#L310)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636674)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636673  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L290](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L290)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636673)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636672  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L305](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L305)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636672)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636671  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L419](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L419)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636671)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636670  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L448](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L448)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636670)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag`
**ID:** 765636669  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/modules/themes/own-pay/gateway.php#L19](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/modules/themes/own-pay/gateway.php#L19)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636669)  

> Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it can lead to a Cross-site scripting (XSS) vulnerability. XSS vulnerabilities occur when untrusted input executes malicious JavaScript code, leading to issues such as account compromise and sensitive information leakage. To prevent this vulnerability, validate the user input, perform contextual output encoding or sanitize the input. In PHP you can encode or sanitize user input with `htmlspecialchars` or use automatic context-aware escaping with a template engine such as Latte.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636668  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [app/install/index.php#L497](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/install/index.php#L497)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636668)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636667  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [index.php#L299](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/index.php#L299)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636667)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636666  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Controller/SystemUpdateController.php#L371](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Controller/SystemUpdateController.php#L371)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636666)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636665  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Http/Controller/AdminUpdateController.php#L106](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Http/Controller/AdminUpdateController.php#L106)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636665)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636664  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Plugin/PluginInstaller.php#L563](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Plugin/PluginInstaller.php#L563)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636664)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636663  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Plugin/PluginRegistry.php#L148](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Plugin/PluginRegistry.php#L148)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636663)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636662  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Service/ImageService.php#L113](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Service/ImageService.php#L113)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636662)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636661  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Service/PluginManager.php#L237](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Service/PluginManager.php#L237)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636661)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636660  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Service/UpdaterService.php#L103](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Service/UpdaterService.php#L103)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636660)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636659  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Service/UpdaterService.php#L234](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Service/UpdaterService.php#L234)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636659)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.lang.security.unlink-use.unlink-use`
**ID:** 765636658  
**Confidence:** ⚪ Low  
**Status:** Open  
**File:** [src/Service/UpdaterService.php#L256](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/src/Service/UpdaterService.php#L256)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636658)  

> Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

#### `php.laravel.security.laravel-path-traversal.laravel-path-traversal`
**ID:** 765636657  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/addons/edit.php#L15](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/addons/edit.php#L15)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636657)  

> The application builds a file path from potentially untrusted data, which can lead to a path traversal vulnerability. An attacker can manipulate the file path which the application uses to access files. If the application does not validate user input and sanitize file paths, sensitive files such as configuration or user data can be accessed, potentially creating or overwriting files. In PHP, this can lead to both local file inclusion (LFI) or remote file inclusion (RFI) if user input reaches this statement. To prevent this vulnerability, validate and sanitize any input that is used to create references to file paths. Also, enforce strict file access controls. For example, choose privileges allowing public-facing applications to access only the required files.

#### `php.laravel.security.laravel-path-traversal.laravel-path-traversal`
**ID:** 765636656  
**Confidence:** 🟠 Medium  
**Risk Tag:** Payments  
**Status:** Open  
**File:** [app/admin/dashboard/gateways/edit.php#L31](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/gateways/edit.php#L31)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636656)  

> The application builds a file path from potentially untrusted data, which can lead to a path traversal vulnerability. An attacker can manipulate the file path which the application uses to access files. If the application does not validate user input and sanitize file paths, sensitive files such as configuration or user data can be accessed, potentially creating or overwriting files. In PHP, this can lead to both local file inclusion (LFI) or remote file inclusion (RFI) if user input reaches this statement. To prevent this vulnerability, validate and sanitize any input that is used to create references to file paths. Also, enforce strict file access controls. For example, choose privileges allowing public-facing applications to access only the required files.

#### `php.laravel.security.laravel-path-traversal.laravel-path-traversal`
**ID:** 765636655  
**Confidence:** 🟠 Medium  
**Risk Tag:** Superuser  
**Status:** Open  
**File:** [app/admin/dashboard/settings/themes-setting.php#L11](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/admin/dashboard/settings/themes-setting.php#L11)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636655)  

> The application builds a file path from potentially untrusted data, which can lead to a path traversal vulnerability. An attacker can manipulate the file path which the application uses to access files. If the application does not validate user input and sanitize file paths, sensitive files such as configuration or user data can be accessed, potentially creating or overwriting files. In PHP, this can lead to both local file inclusion (LFI) or remote file inclusion (RFI) if user input reaches this statement. To prevent this vulnerability, validate and sanitize any input that is used to create references to file paths. Also, enforce strict file access controls. For example, choose privileges allowing public-facing applications to access only the required files.

#### `php.laravel.security.laravel-path-traversal.laravel-path-traversal`
**ID:** 765636654  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L498](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L498)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636654)  

> The application builds a file path from potentially untrusted data, which can lead to a path traversal vulnerability. An attacker can manipulate the file path which the application uses to access files. If the application does not validate user input and sanitize file paths, sensitive files such as configuration or user data can be accessed, potentially creating or overwriting files. In PHP, this can lead to both local file inclusion (LFI) or remote file inclusion (RFI) if user input reaches this statement. To prevent this vulnerability, validate and sanitize any input that is used to create references to file paths. Also, enforce strict file access controls. For example, choose privileges allowing public-facing applications to access only the required files.

#### `php.laravel.security.laravel-path-traversal.laravel-path-traversal`
**ID:** 765636653  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [app/core/adapter.php#L500](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/app/core/adapter.php#L500)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636653)  

> The application builds a file path from potentially untrusted data, which can lead to a path traversal vulnerability. An attacker can manipulate the file path which the application uses to access files. If the application does not validate user input and sanitize file paths, sensitive files such as configuration or user data can be accessed, potentially creating or overwriting files. In PHP, this can lead to both local file inclusion (LFI) or remote file inclusion (RFI) if user input reaches this statement. To prevent this vulnerability, validate and sanitize any input that is used to create references to file paths. Also, enforce strict file access controls. For example, choose privileges allowing public-facing applications to access only the required files.

#### `php.laravel.security.laravel-path-traversal.laravel-path-traversal`
**ID:** 765636652  
**Confidence:** 🟠 Medium  
**Status:** Open  
**File:** [index.php#L201](https://github.com/ownpay/blob/ed8e01a7c638ca00c26b6fd1321f78de630fa016/index.php#L201)  
**Created:** 2026-04-27  
**Semgrep Link:** [View in Semgrep](https://semgrep.dev/orgs/builder_hall/findings/765636652)  

> The application builds a file path from potentially untrusted data, which can lead to a path traversal vulnerability. An attacker can manipulate the file path which the application uses to access files. If the application does not validate user input and sanitize file paths, sensitive files such as configuration or user data can be accessed, potentially creating or overwriting files. In PHP, this can lead to both local file inclusion (LFI) or remote file inclusion (RFI) if user input reaches this statement. To prevent this vulnerability, validate and sanitize any input that is used to create references to file paths. Also, enforce strict file access controls. For example, choose privileges allowing public-facing applications to access only the required files.
