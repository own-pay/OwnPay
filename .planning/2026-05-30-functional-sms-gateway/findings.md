# Findings & Decisions: Functional SMS Gateway Addon

## Requirements
- SMS Gateway addon must implement `SmsProviderInterface` to integrate with `CommunicationService`.
- Support automatic, togglable SMS notifications to customers:
  - On invoice creation (`invoice.created` event).
  - On successful payment (`payment.transaction.completed` event).
- Provide customizable SMS templates with dynamic placeholders.

## Research Findings
- The SMS Gateway addon is located in `modules/addons/sms-gateway/`.
- `CommunicationService` resolves providers by checking for the capability `Capability::COMMUNICATION` and asserting the class is an instance of `SmsProviderInterface`.
- Invoices are created via `InvoiceService::create()` and dispatch the `'invoice.created'` hook from `InvoiceController`.
- Transaction events dispatch `'payment.transaction.completed'` upon success.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Interface Conformance | Implement `SmsProviderInterface` methods: `slug()`, `send()`, `status()`, and `balance()`. |
| Settings Enrichment | Add toggles (`send_on_invoice_created`, `send_on_payment_success`) and templates (`invoice_created_template`, `payment_success_template`) in fields() and manifest.json. |
