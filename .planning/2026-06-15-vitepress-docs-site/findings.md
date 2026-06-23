---
name: vitepress-docs-findings
description: Technical discoveries for the VitePress docs site task
metadata:
  type: project
---

# Findings

## Environment
- Node: v24.15.0
- npm: 11.12.1
- VitePress latest: 1.6.4

## Existing Docs Structure (C:\laragon\backup\OwnPay\docs\user_guide\)
- README.md — master index, rich content, has mermaid diagram
- 12 sections: auth, dashboard, payments, gateways, people, mobile-sms, reports-finance, appearance, system, account, public, developers
- Screenshots are PNG files inside `screenshots/` subdirectories next to each .md
- References use relative paths: `./screenshots/image.png`

## VitePress Notes
- Source dir will be the project root (all .md at root level and subdirs)
- Screenshots: use `public/` folder and absolute paths, OR keep relative paths next to md files
- Decision: copy screenshots into the same relative structure as the md files (VitePress handles relative asset resolution via Vite)
- Images referenced as `./screenshots/image.png` will work if the screenshots folder is next to the md file

## Key Content for API Reference
- From developer-hub.md: endpoint `/api/v1/payment-intents`, bearer auth with `op_` prefix keys
- Auth: `Authorization: Bearer op_<key>`
- Webhook HMAC verification required
