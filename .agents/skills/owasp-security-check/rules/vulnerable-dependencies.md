---
title: Vulnerable and Outdated Dependencies
impact: MEDIUM
tags: [dependencies, supply-chain, owasp-a06]
---

# Vulnerable and Outdated Dependencies

Check for outdated packages with known security vulnerabilities and supply chain risks.

## Why

- **Known exploits**: Public CVEs make attacks easy
- **Supply chain attacks**: Compromised packages
- **Transitive dependencies**: Vulnerabilities deep in dependency tree
- **Maintenance risk**: Unmaintained packages won't get patches

## What to Check

- [ ] Dependencies with known CVEs or security advisories
- [ ] Severely outdated packages (major versions behind current)
- [ ] Packages without recent updates (abandoned/unmaintained)
- [ ] Missing dependency lockfiles
- [ ] Wildcard or loose version constraints in production
- [ ] Unused dependencies bloating the project
- [ ] Development dependencies bundled in production builds
- [ ] Transitive vulnerabilities in indirect dependencies

## Bad Patterns

```typescript
// Bad: Wildcard versions allow unexpected updates
// package.json
{
  "dependencies": {
    "express": "*",           // Any version can be installed
    "react": "^18.0.0"        // Minor/patch versions can change
  }
}

// Bad: No lockfile means versions drift between installs
// Missing: package-lock.json, yarn.lock, pnpm-lock.yaml, etc.

// Bad: Dev dependencies mixed with production
{
  "dependencies": {
    "express": "4.18.2",
    "jest": "29.5.0",         // Should be devDependency
    "eslint": "8.40.0"        // Should be devDependency
  }
}
```

## Good Patterns

````typescript
// Good: Pinned versions with lockfile
{
  "dependencies": {
    "express": "4.18.2",      // Exact version pinned
    "react": "18.2.0"
  },
  "devDependencies": {
    "jest": "29.5.0",
    "eslint": "8.40.0"
  }
}
// Plus: Lockfile committed (package-lock.json, yarn.lock, etc.)

// Good: Regular dependency audits in CI/CD
// .github/workflows/security.yml
```yaml
name: Security Audit
on: [push, pull_request]
jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - run: npm audit --production  # Or: pip-audit, bundle audit, etc.
````

**Before installing new packages:**

- Check package age and download stats
- Review maintainer history
- Scan for known vulnerabilities
- Verify package scope matches intent (avoid typosquatting)

## Rules

1. **Always use lockfiles** - Commit dependency lockfiles for reproducible builds
2. **Pin production versions** - Use exact versions for production dependencies
3. **Audit regularly** - Run security audits in CI/CD and before deployments
4. **Keep dependencies updated** - Use automated update tools
5. **Separate dev dependencies** - Keep development tools separate from production
6. **Remove unused packages** - Regularly clean up unused dependencies
7. **Review before adding** - Check package age, maintainers, and reputation
8. **Monitor advisories** - Subscribe to security advisories for critical dependencies
