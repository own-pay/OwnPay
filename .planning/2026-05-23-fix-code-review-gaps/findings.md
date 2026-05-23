# Findings & Decisions

## Requirements
- Prevent suspended merchants from using payment intent checkout URLs (`/checkout/intent/{token}`) on the master domain.
- Optimize BCMath intermediate types in `InvoiceService.php` to avoid temporary float castings.

## Research Findings
- `PaymentIntentCheckoutController.php` processes `/checkout/intent/{token}` and `/checkout/intent/{token}/pay` without querying the merchant's status in `op_merchants`.
- `InvoiceService.php` casts BCMath string calculations back to floats prior to database insertion.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Validate merchant status in all PaymentIntentCheckoutController actions | Prevents rendering checkout and processing captures for suspended merchants on the master domain. |
| Pass BCMath output strings directly to database binding in InvoiceService | Retains absolute decimal precision up to the database storage layer. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [code_review_report.md](file:///C:/Users/iamna/.gemini/antigravity-cli/brain/6141ae0b-4d9e-4d76-9465-c4e8f14f95d9/code_review_report.md)

