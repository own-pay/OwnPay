---
description: This workflow is triggered when the agent needs to resume a task from where it left off, regardless of the reason for the interruption (errors, timeout, manual pause).
---

## 🎯 Objective
Seamlessly resume work by reconstructing the previous state, identifying the next step, and executing it using relevant skills and the established modern architectural guidelines (PSR-4, DI, Middleware).

## 📋 Steps to Continue

### 1. Context Reconstruction
- **Read Implementation Plan**: Load the latest `implementation_plan.md` to understand the high-level goal and agreed-upon approach.
- **Read Task List**: Load `task.md` to see what is completed `[x]`, in progress `[/]`, and pending `[ ]`.
- **Verify Architecture**: Review `src/Kernel.php` and `src/Container.php` to ensure the resumed work adheres to the dependency injection and middleware pipeline rules.
- **Consult AGENT.md**: Review the latest rules in `.agents/AGENT.md`.
- **Verify Branch**: Check the current git branch (`git branch --show-current`) to ensure it matches the plan.

### 2. Execution Strategy
- **Identify Next Item**: Locate the first incomplete `[ ]` or partially complete `[/]` item in `task.md`.
- **Skill Assessment**: Identify which agent skills (e.g., `php-symfony`, `senior-security`, `senior-architect`) are required for the next step.
- **Clarification**: If the previous state is ambiguous or the next step is underspecified, **ask the user for clarification** before proceeding.

### 3. Resume & Verify
- **Resume Execution**: Start working on the identified task item.
- **Continuous Update**: Keep `task.md` updated as progress is made.
- **Verification**: Run tests (`./vendor/bin/phpunit`) or manual checks to ensure the resumed work aligns with the modernized PSR-4 architecture.

## 🛠️ Usage
**Trigger Phrases**:
- "Continue the task"
- "Resume from where you left off"
- "Fix the error and continue"
- "What's next in the plan?"
- "Continue"
- "Resume"