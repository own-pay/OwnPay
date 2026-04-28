# Skills Index

A comprehensive mapping of all available skills in the `.agents/skills/` directory, detailing their purpose, core abilities, and when to use them.

## Summary Table

| Skill | Description | Primary Ability |
| :--- | :--- | :--- |
| [**anti-reversing-techniques**](anti-reversing-techniques/SKILL.md) | Software protection & anti-analysis techniques | Identification and bypass of anti-debugging/VM checks |
| [**brainstorming**](brainstorming/SKILL.md) | Collaborative design and requirement gathering | Turning ideas into specs and designs before implementation |
| [**caveman**](caveman/SKILL.md) | Token-efficient communication mode | Reducing token usage while maintaining technical accuracy |
| [**changelog-generator**](changelog-generator/SKILL.md) | Automated release documentation | Generating changelogs from Conventional Commits |
| [**cto-advisor**](cto-advisor/SKILL.md) | Technical leadership and strategy | Tech debt assessment, team scaling, and architecture governance |
| [**flutter-architecture**](flutter-architecture/SKILL.md) | Flutter-specific architectural patterns | MVVM implementation and project organization (Feature-First) |
| [**flutter-expert**](flutter-expert/SKILL.md) | Master-level Flutter development | Advanced widget composition and multi-platform optimization |
| [**owasp-security-check**](owasp-security-check/SKILL.md) | Web application security auditing | Systematic OWASP Top 10 vulnerability scanning |
| [**pci-compliance**](pci-compliance/SKILL.md) | Payment card security standards | Implementing secure payment processing and data protection |
| [**php-fundamentals**](php-fundamentals/SKILL.md) | Modern PHP programming (PHP 8.x) | Mastering PHP syntax, OOP, and type systems |
| [**php-symfony**](php-symfony/SKILL.md) | Symfony framework mastery | Enterprise-grade backend development with Symfony/Doctrine |
| [**project-structure**](project-structure/SKILL.md) | Code organization best practices | Auditing and recommending project directory layouts |
| [**refactor-assistant**](refactor-assistant/SKILL.md) | Automated code improvement | Identifying code smells and executing refactoring patterns |
| [**security**](security/SKILL.md) | General security auditing & GitLeaks | OWASP audits, secret scanning, and dependency checks |
| [**senior-architect**](senior-architect/SKILL.md) | System design and technical decisions | Architecture diagrams, tech stack selection, and ADRs |
| [**senior-prompt-engineer**](senior-prompt-engineer/SKILL.md) | AI and LLM optimization | Prompt engineering, RAG evaluation, and agent design |
| [**senior-security**](senior-security/SKILL.md) | Security engineering and threat modeling | STRIDE analysis, secure architecture, and incident response |
| [**sql-optimization-patterns**](sql-optimization-patterns/SKILL.md) | Database performance tuning | Query optimization, indexing strategies, and EXPLAIN analysis |

---

## Detailed Skill Mapping

### 1. Anti-Reversing Techniques
- **Purpose**: Understand and bypass protections encountered during software analysis.
- **Abilities**: Identify and neutralize anti-debugging, anti-VM, and code obfuscation (flattening, opaque predicates, hashing).
- **Use When**: Analyzing malware, reverse engineering protected binaries, or building CTF challenges.

### 2. Brainstorming
- **Purpose**: MANDATORY design phase before implementation.
- **Abilities**: Natural collaborative dialogue to explore requirements, propose approaches, and produce a formal design spec.
- **Use When**: Starting any new feature, component, or behavior modification.

### 3. Caveman
- **Purpose**: Token efficiency.
- **Abilities**: Terse communication by dropping articles and fluff while preserving technical substance.
- **Use When**: Requested by user or when maximum token efficiency is needed.

### 4. Changelog Generator
- **Purpose**: Audit-ready release notes.
- **Abilities**: Parsing Conventional Commits, semantic versioning detection, and markdown/JSON rendering.
- **Use When**: Before publishing a release tag or during CI pipelines.

### 5. CTO Advisor
- **Purpose**: Technical leadership for engineering teams.
- **Abilities**: Technical debt strategy, team scaling models, Architecture Decision Records (ADRs), and crisis management.
- **Use When**: Assessing long-term technical health, scaling teams, or making high-stakes architecture choices.

### 6. Flutter Architecture
- **Purpose**: Building scalable Flutter apps.
- **Abilities**: Implementing MVVM, choosing between Feature-First vs. Layer-First structures, and managing unidirectional data flow.
- **Use When**: Designing or refactoring the core structure of a Flutter application.

### 7. Flutter Expert
- **Purpose**: Deep Flutter and Dart mastery.
- **Abilities**: Custom widget painting, Impeller optimization, platform channels (iOS/Android/Web/Desktop), and state management (Riverpod, Bloc, etc.).
- **Use When**: Implementing high-performance UI, native integrations, or complex multi-platform features.

### 8. OWASP Security Check
- **Purpose**: Systematic security auditing.
- **Abilities**: 20 rules across 5 categories covering Authentication, Data Protection, and Input/Output security.
- **Use When**: Reviewing code for vulnerabilities before production deployment.

### 9. PCI Compliance
- **Purpose**: Secure payment processing.
- **Abilities**: Implementation of the 12 core PCI DSS requirements, tokenization, vaulting, and encryption of cardholder data.
- **Use When**: Building payment systems or handling any sensitive credit card information.

### 10. PHP Fundamentals
- **Purpose**: Modern PHP 8.x mastery.
- **Abilities**: Type system (union/intersection), OOP patterns, property hooks (PHP 8.4), and Composer dependency management.
- **Use When**: Writing core PHP logic, refactoring legacy PHP code, or learning modern patterns.

### 11. PHP Symfony
- **Purpose**: Enterprise backend development.
- **Abilities**: Doctrine ORM, Dependency Injection container, Messenger (async), and API Platform integration.
- **Use When**: Working on robust web applications or microservices using the Symfony framework.

### 12. Project Structure
- **Purpose**: Organizing project files logically.
- **Abilities**: Auditing directory anti-patterns, enforcing colocation, and choosing appropriate organization models (feature-based vs. layer-based).
- **Use When**: Deciding where code should live or cleaning up a disorganized repository.

### 13. Refactor Assistant
- **Purpose**: Automated code quality improvement.
- **Abilities**: Extracting functions, removing duplication, simplifying complex conditionals, and identifying SOLID violations.
- **Use When**: Improving maintainability without changing external behavior.

### 14. Security
- **Purpose**: General security hygiene.
- **Abilities**: Setting up GitLeaks for secret protection, running OWASP Top 10 scans, and auditing dependencies.
- **Use When**: Setting up pre-commit hooks or performing initial codebase security scans.

### 15. Senior Architect
- **Purpose**: High-level system design.
- **Abilities**: Generating Mermaid/PlantUML diagrams, database selection workflows, and monolith vs. microservices analysis.
- **Use When**: Designing system architecture, evaluating tech stacks, or visualizing complex module relationships.

### 16. Senior Prompt Engineer
- **Purpose**: Optimizing AI interactions.
- **Abilities**: Prompt token analysis, RAG retrieval evaluation, agentic workflow design (ReAct), and few-shot example curation.
- **Use When**: Building AI-powered features, optimizing LLM costs, or designing complex agent systems.

### 17. Senior Security
- **Purpose**: Security engineering and architecture.
- **Abilities**: STRIDE threat modeling, Zero Trust architecture design, penetration testing, and incident response runbooks.
- **Use When**: Designing secure systems from scratch or responding to a security incident.

### 18. SQL Optimization Patterns
- **Purpose**: Database performance tuning.
- **Abilities**: Analyzing EXPLAIN plans, implementing B-Tree/GIN indexes, eliminating N+1 queries, and optimizing pagination.
- **Use When**: Debugging slow queries or designing high-traffic database schemas.
