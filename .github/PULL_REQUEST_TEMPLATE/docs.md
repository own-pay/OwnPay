# Documentation & Translation Pull Request

> [!IMPORTANT]
> **Branch Rule**: Pull Requests MUST target the **`dev`** branch.

---

## 📖 Summary of Documentation Changes

<!-- What documentation or translation was added, updated, or fixed? -->

**Related Issue**: Fixes #<!-- issue number -->

### 📚 Areas Updated

- [ ] API Reference / Endpoint Specs (`docs.ownpay.org`)
- [ ] Documentation / Guides (`ownpay.org/docs`)
- [ ] Deployment / Self-Hosting Runbooks (`docs/LOCAL_SETUP.md`, etc.)
- [ ] Architecture / Core Concepts (`docs/ARCHITECTURE.md`)
- [ ] Translation / Language Catalogs (`config/languages/` or `docs/TRANSLATIONS.md`)
- [ ] Readme / Community Files

---

## 🌍 Language Catalog Checklist (If Translation PR)

- [ ] Target language ISO code: `config/languages/<code>.json`
- [ ] Preserved all `:parameter` and `{placeholder}` tokens in translated strings.
- [ ] Validated JSON syntax (`jq . config/languages/<code>.json` or linter).

---

## ⚖️ DCO & License

- [ ] My commits include a **DCO sign-off** (`git commit -s`).
- [ ] I agree to license this work under the **GNU AGPL-3.0 License**.
