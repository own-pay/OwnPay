---
trigger: model_decision
description: Apply when preparing, formatting, or executing system commands, scripts, or build tasks in Windows PowerShell.
---

# Windows PowerShell Syntax & Execution Rules

To prevent syntax errors, execution failures, and unnecessary token consumption on Windows systems, the agent MUST strictly adhere to Windows PowerShell syntax constraints when constructing and executing shell commands.

## 1. Directory and File Operations

Never use Linux/POSIX commands. Always use the native PowerShell Cmdlets:

| POSIX Command | PowerShell Cmdlet (Use This) | Notes / Parameters |
|---|---|---|
| `ls` | `Get-ChildItem` | Lists directory contents. |
| `cat file.txt` | `Get-Content file.txt` | Reads file contents. |
| `mkdir -p path/to/dir` | `New-Item -ItemType Directory -Force path\to\dir` | Creates directory recursively. |
| `rm -rf path` | `Remove-Item -Path path -Recurse -Force` | Deletes recursively and forcefully. |
| `cp src dest` | `Copy-Item -Path src -Destination dest -Force` | Copies files/folders. |
| `mv src dest` | `Move-Item -Path src -Destination dest -Force` | Moves files/folders. |
| `touch file.txt` | `New-Item -ItemType File -Force file.txt` | Creates or updates a file. |
| `pwd` | `(Get-Location).Path` | Returns the current working directory path. |

## 2. Path Formats and Separators

* **Backslashes:** Always use backslashes (`\`) for local Windows file system paths in commands and arguments (e.g., `.\src\Kernel.php`, `.planning\legacy\`).
* **Path Joining:** When constructing paths programmatically in PowerShell scripts, use `Join-Path` instead of string interpolation (e.g., `Join-Path $PlanRoot $PlanId`).

## 3. Environment Variables

* **Setting Variables:** Never use `export KEY=value` or `KEY=value command`.
  * Set a variable for the session: `$env:PLAN_ID = "2026-05-23-strict-agent-rules"`
* **Reading Variables:** Access environment variables using the `$env:` namespace:
  * Read: `$env:PLAN_ID` (Do NOT use `$PLAN_ID` as that references a local PowerShell variable, not an environment variable).

## 4. Command Chaining and Control Flow

* **No `&&` or `||` in default PowerShell 5.1:** Older Windows PowerShell versions do not support POSIX operators `&&` and `||`.
  * **Safe Chaining:** Use the semicolon `;` to separate multiple commands (e.g., `Command-One; Command-Two`).
  * **Conditional Execution:** Use standard PowerShell conditionals if success checks are required:
    ```powershell
    # Execute Command-Two only if Command-One succeeds (exit code 0)
    Command-One; if ($LastExitCode -eq 0) { Command-Two }
    ```

## 5. Output Redirection and Encodings

* **Avoid `>` and `>>`:** By default, standard redirection (`>`) in Windows PowerShell 5.1 uses UTF-16LE encoding, which corrupts files like `.active_plan` or `.gitignore` that expect UTF-8.
* **UTF-8 Writing:** Always use `Out-File` or `Set-Content` with explicit encoding parameters:
  * Write: `"value" | Out-File -FilePath .planning\.active_plan -Encoding UTF8 -NoNewline`
  * Append: `"log string" | Add-Content -Path log.txt -Encoding UTF8`

## 6. Testing Path and File Existence

* Never use POSIX test operators like `[ -f file ]` or `[ -d dir ]`.
* Use the `Test-Path` cmdlet:
  * File check: `if (Test-Path -Path .\task_plan.md -PathType Leaf) { ... }`
  * Directory check: `if (Test-Path -Path .\.planning -PathType Container) { ... }`

## 7. Exit Codes and Success Verification

* **Native Executables:** When running native Windows binaries (e.g., `php`, `composer`, `git`, `phpunit`), verify the process exit code using `$LastExitCode` (0 = Success, Non-Zero = Failure).
  * Example: `php vendor/bin/phpunit; if ($LastExitCode -ne 0) { throw "Tests Failed!" }`
* **Cmdlet Execution:** When running PowerShell cmdlets, check the `$Query` or automatic variable `$?.` (True = Success, False = Failure).
