---
trigger: always_on
---

# Documentation & Rules Synchronization Rule

To prevent outdated documentation, logical drifts, and context mismatch across agent sessions, the agent MUST strictly enforce synchronous updates to rule files and Markdown documentation under `docs/v2/` whenever code changes occur.

## Mandatory Update Triggers & Target Files

### 1. Architectural & High-Level Code Changes
If any change is made to routes, database schemas, framework boot logic, security middleware, container settings, or core directory structures:
* **MUST UPDATE:** [ARCHITECTURE.md](ARCHITECTURE.md) and [AGENTS.md](AGENTS.md).
* **Rule Synchronization:** If the change involves a new critical codebase invariant, pattern, or security control that subsequent agents must remember, you MUST create a new rule file in `.agents/rules/` or update the most relevant existing rule file.

### 2. Plugin & Hooks System Modification
If any plugin hook (event action or filter), event trigger, or registration endpoint is added, modified, or removed:
* **MUST UPDATE:** [hooks-reference.md](docs/v2/plugins/hooks-reference.md).
If the mechanics of the plugin loader, event manager, sandboxing, or manifest format are updated:
* **MUST UPDATE:** [developer-guide.md](docs/v2/plugins/developer-guide.md).

### 3. API Changes & OpenAPI Documentation
If REST/GraphQL API routes, payload structures, parameter options, or JSON response schemas are introduced, edited, or deprecated:
* **MUST UPDATE:** All API documentation under [docs/v2/api/](docs/v2/api/) to reflect changes.
* **Format Constraint:** All API definitions MUST follow the **OpenAPI 3.2.0** specification standard strictly.

### 4. General Documentation Maintenance
* **Strict Synchronization:** Ensure all Markdown specifications under [docs/v2/](docs/v2/) remain fully synchronized and up to date with the latest code state before completing the task. Never complete a task leaving stale documentation behind.