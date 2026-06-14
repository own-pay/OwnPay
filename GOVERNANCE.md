# OwnPay Project Governance

This document describes how the OwnPay project is governed — who makes decisions, how they are made, and how contributors can grow into positions of greater responsibility. Our goal is to be **transparent, open, and merit-based** while keeping a clear line of accountability for a project that handles real financial infrastructure.

---

## Guiding Principles

- **Open by default.** Discussion, decisions, and roadmap happen in the open wherever possible.
- **Security and correctness first.** OwnPay handles money; quality is never sacrificed for speed.
- **Merit over politics.** Influence is earned through sustained, high-quality contribution.
- **Community-driven.** The project exists to serve the people who self-host and build on it.

---

## Roles

### Users
Anyone who runs OwnPay. Users contribute by reporting bugs, requesting features, asking questions, and helping others in the community.

### Contributors
Anyone who contributes code, documentation, translations, gateways/plugins, reviews, or design. Contributions are made via pull requests under the [Contributing Guide](CONTRIBUTING.md). There is no barrier to becoming a contributor beyond following that guide.

### Maintainers
Trusted contributors with merge rights to the repository. Maintainers:

- Review and merge pull requests.
- Triage issues and shepherd discussions.
- Uphold coding standards, security, and the [Code of Conduct](CODE_OF_CONDUCT.md).
- Help shape the [roadmap](ROADMAP.md).

Maintainers are added by the Lead Maintainer based on a sustained track record of quality contributions and good judgment.

### Lead Maintainer (Project Lead)
OwnPay currently follows a **lead-maintainer model**. The Lead Maintainer is responsible for the overall technical direction, has final say on decisions when consensus cannot be reached, manages releases, and stewards the project's long-term health.

- **Lead Maintainer:** **Fattain Naime** — [iamnaime.info.bd](https://iamnaime.info.bd)

As the project and its community grow, governance is expected to evolve toward a broader maintainer team and, eventually, a formal steering committee.

---

## Decision Making

Most decisions are made through **lazy consensus** in issues and pull requests:

1. A change is proposed (issue, discussion, or PR).
2. The community and maintainers discuss it in the open.
3. If there are no sustained, reasoned objections, the change moves forward.
4. If consensus cannot be reached, the **Lead Maintainer makes the final decision**, with the rationale recorded publicly.

**Larger or breaking changes** (architecture shifts, security-sensitive features, dependency additions, anything affecting financial correctness) require explicit maintainer review and sign-off before merging.

---

## Releases

- The Lead Maintainer (or a delegated maintainer) cuts releases and publishes them via [GitHub Releases](https://github.com/own-pay/OwnPay/releases).
- Releases follow [Semantic Versioning](https://semver.org/). OwnPay is currently in the `0.x.x` Public Beta line on the road to a stable `1.0.0`.
- Security fixes are prioritized and may ship out of the normal cadence — see [SECURITY.md](SECURITY.md).

---

## Changing Governance

This governance model is intentionally lightweight for OwnPay's current stage. Proposed changes to governance are made via pull request to this document and are subject to Lead Maintainer approval after community discussion. As the contributor base expands, we are committed to evolving toward more distributed, community-led governance.

---

## Contact

For governance questions, partnership inquiries, or anything that doesn't fit an issue or discussion, reach out at **[ping@ownpay.org](mailto:ping@ownpay.org)**.

---

❤️ Built by the **Community**, for the **Community**.
