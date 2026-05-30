# Findings & Decisions: Advanced Telegram Bot Control Center

## Requirements
- Add more commands and make them more flexible.
- Add Telegram bot inline buttons (inline keyboard markup).
- Implement interactive admin commands to create payment links, invoices, check status, check customers, and manage transactions dynamically.
- Strict security controls, verifying `chat_id` on all incoming actions.

## Research Findings
- The existing bot plugin `OwnPay\Modules\Addons\TelegramBot\Plugin` is located in `modules/addons/telegram-bot/Plugin.php`.
- The plugin currently exposes `/createlink`, `/createinvoice`, `/status`, `/today`, `/recent`, `/start` commands.
- The default keyboard is retrieved using `startKeyboard()` returning an `inline_keyboard` grid.
- We have full access to:
  - `Database` to run parameterized database queries.
  - `CustomerPiiService` to search/decrypt/manage customers.
  - `InvoiceService` and `PaymentLinkService` to instantiate payment paths.
  - `TransactionRepository`, `RefundRepository`, `AuditLogRepository`, and `DisputeRepository` to pull admin analytics and list recent actions.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Interactive Callbacks | Use Telegram's callback query structure (`callback_query.data`) to execute admin procedures like viewing transaction details, refunding, showing customer info. |
| Strict Schema Isolation | Filter queries by `merchant_id` context. By default, the bot operates on the first active merchant (merchant_id = 1) unless otherwise configured. |

## Resources
- Telegram Bot API Documentation: Webhooks, sendMessage, answerCallbackQuery, InlineKeyboardMarkup, CallbackQuery.
