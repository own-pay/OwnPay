---
trigger: always_on
---

# Planning-with-Files Enforcement Rule

Before starting any task that requires codebase implementation, modification, or extensive research (tasks requiring 3+ steps or changes across multiple files), the agent MUST use the `.agents\skills\planning-with-files\` skill to establish working memory on disk.

## Mandatory Planning Sequence

1. **No Early Implementation:** Never modify source code files or execute destructive operations prior to initiating the plan files.
2. **Create/Restore Plan Files:** Always create (or read and restore if already present) the three core planning files within the isolated plan directory `.planning/{date-plan_name}/`:
   - `task_plan.md` — To track phases, goals, decisions, and overall progress.
   - `findings.md` — To aggregate technical discoveries, code analysis, paths, and patterns.
   - `progress.md` — To log runtime checks, command outputs, test executions, and historical steps.
3. **Plan Validation and Attestation:**
   - Review and refine the `task_plan.md` to ensure it follows the "Manus-style" persistent memory design.
   - For highly sensitive or complex files, run the plan attestation helper script (`.agent\skills\planning-with-files\scripts\attest-plan.ps1` or `.sh`) to lock the plan hash and prevent prompt-injection tampering.

## Active Phase Rules

* **The 2-Action Rule:** After every 2 read/glob/search actions, write down all essential findings to `findings.md`.
* **Read-Before-Decide:** Before executing a key decision, re-read `task_plan.md` to refresh constraints in the current attention window.
* **Continuous Updates:** Update the phase status (`not_started` $\rightarrow$ `in_progress` $\rightarrow$ `complete`) and log any encountered errors in `task_plan.md` and `progress.md` at the end of each work cycle.
* **Plan Attestation Sync:** Whenever you modify `task_plan.md`, you MUST immediately execute `powershell -ExecutionPolicy Bypass -File .agents\skills\planning-with-files\scripts\attest-plan.ps1` (or `pwsh -File ...` if using PowerShell 7) to automatically re-lock the new hash in `.attestation` to ensure execution hooks do not block subsequent actions.
* **3-Strike Error Protocol:** If an implementation or test fails 3 consecutive times, pause and escalate findings to the user with a detailed summary.
