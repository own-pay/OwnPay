<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\TelegramBot;

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Telegram Bot Addon - transaction alerts + commands.
 * senior-security: Bot token stored in DB, never logged.
 */
final class Plugin implements PluginInterface
{
    /** @var array<string, string> */
    private array $settings = [];
    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Telegram Bot',
            'slug'        => 'telegram-bot',
            'version'     => '1.0.0',
            'description' => 'Telegram bot for transaction alerts and admin commands.',
            'author'      => 'OwnPay',
            'type'        => 'addon',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::NOTIFICATION, Capability::WEBHOOK];
    }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('payment.transaction.completed', [$this, 'onCompleted'], 20);
        $events->addAction('payment.transaction.failed', [$this, 'onFailed'], 20);
        $events->addAction('plugin.settings.saved', [$this, 'onSettingsSaved'], 20);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            if ($repo instanceof \OwnPay\Repository\SettingsRepository) {
                $this->settings = $repo->getGroup('plugin.telegram-bot');
            }
        }
    }

    public function deactivate(Container $container): void {}

    public function uninstall(Container $container): void
    {
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            if ($repo instanceof \OwnPay\Repository\SettingsRepository) {
                $repo->deleteGroup('plugin.telegram-bot');
            }
        }
    }

    public function fields(): array
    {
        return [
            [
                'name'    => 'bot_token',
                'label'   => 'Bot Token',
                'type'    => 'password',
                'default' => '',
                'help'    => 'Get this from @BotFather on Telegram.',
            ],
            [
                'name'    => 'chat_id',
                'label'   => 'Chat ID',
                'type'    => 'text',
                'default' => '',
                'help'    => 'Telegram chat ID for notifications. Use @userinfobot to find yours.',
            ],
            [
                'name'    => 'alert_on_success',
                'label'   => 'Alert on Successful Payment',
                'type'    => 'toggle',
                'default' => '1',
            ],
            [
                'name'    => 'alert_on_failure',
                'label'   => 'Alert on Failed Payment',
                'type'    => 'toggle',
                'default' => '1',
            ],
        ];
    }

    /** @param array<string, mixed> $txn */
    public function onCompleted(array $txn): void
    {
        $midVal = $txn['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : null;
        $settings = $this->getSettingsForMerchant($merchantId);

        if (empty($settings['alert_on_success'])) return;
        $this->sendMessage($this->formatAlert('✅ Payment Received', $txn), null, null, $settings);
    }

    /** @param array<string, mixed> $txn */
    public function onFailed(array $txn): void
    {
        $midVal = $txn['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : null;
        $settings = $this->getSettingsForMerchant($merchantId);

        if (empty($settings['alert_on_failure'])) return;
        $this->sendMessage($this->formatAlert('❌ Payment Failed', $txn), null, null, $settings);
    }

    /** @param array<string, mixed> $txn */
    private function formatAlert(string $title, array $txn): string
    {
        $amount = is_scalar($txn['amount'] ?? null) ? (string) $txn['amount'] : '0.00';
        $currency = is_scalar($txn['currency'] ?? null) ? (string) $txn['currency'] : 'BDT';
        $trxId = is_scalar($txn['trx_id'] ?? null) ? (string) $txn['trx_id'] : 'N/A';
        $gatewayStr = is_scalar($txn['gateway_slug'] ?? null) ? (string) $txn['gateway_slug'] : 'N/A';
        $custVal = $txn['customer_name'] ?? ($txn['customer_email'] ?? 'Anonymous');
        $customer = is_scalar($custVal) ? (string) $custVal : 'Anonymous';

        return "{$title}\n\n"
            . "💰 Amount: {$currency} {$amount}\n"
            . "🆔 Ref: `{$trxId}`\n"
            . "🏦 Gateway: {$gatewayStr}\n"
            . "👤 Customer: {$customer}\n"
            . "🕐 " . date('Y-m-d H:i:s');
    }

    /**
     * Webhook handler - /plugins/telegram-bot/webhook
     */
    public function handleWebhook(Request $req): Response
    {
        $body = $req->json();
        if (!is_array($body)) {
            return Response::json(['ok' => false], 400);
        }

        // Handle Callback Queries (Inline Button clicks)
        if (isset($body['callback_query']) && is_array($body['callback_query'])) {
            $cb = [];
            foreach ($body['callback_query'] as $k => $v) {
                $cb[(string)$k] = $v;
            }
            return $this->handleCallbackQuery($cb);
        }

        $message = $body['message'] ?? [];
        if (!is_array($message)) {
            $message = [];
        }
        $text = trim(is_string($message['text'] ?? null) ? $message['text'] : '');
        $chat = $message['chat'] ?? null;
        $chatId = '';
        if (is_array($chat) && isset($chat['id']) && is_scalar($chat['id'])) {
            $chatId = (string) $chat['id'];
        }

        if ($chatId !== ($this->settings['chat_id'] ?? '')) {
            return Response::json(['ok' => false], 403);
        }

        $result = match (true) {
            str_starts_with($text, '/createlink')    => $this->cmdCreateLink($text),
            str_starts_with($text, '/createinvoice') => $this->cmdCreateInvoice($text),
            str_starts_with($text, '/status')        => $this->cmdStatus($text),
            str_starts_with($text, '/today')         => $this->cmdToday(),
            str_starts_with($text, '/recent')        => $this->cmdRecent(),
            str_starts_with($text, '/customers')     => $this->cmdCustomers(),
            str_starts_with($text, '/disputes')      => $this->cmdDisputes(),
            str_starts_with($text, '/refunds')       => $this->cmdRefunds(),
            str_starts_with($text, '/gateways')      => $this->cmdGateways(),
            str_starts_with($text, '/start') || str_starts_with($text, '/help') => [
                'text' => "🤖 *OwnPay Advanced Command Center*\n\nSelect a dashboard action or use the command reference below.",
                'keyboard' => $this->startKeyboard()
            ],
            default => null,
        };

        if ($result !== null) {
            $this->sendMessage($result['text'], $chatId, $result['keyboard']);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * Handles Callback Query (Inline Keyboard interaction) updates.
     *
     * @param array<string, mixed> $cb Telegram CallbackQuery payload structure.
     * @return Response HTTP response envelope.
     */
    private function handleCallbackQuery(array $cb): Response
    {
        $idVal = $cb['id'] ?? '';
        $id = is_string($idVal) ? $idVal : '';
        $dataVal = $cb['data'] ?? '';
        $data = is_string($dataVal) ? $dataVal : '';
        
        $message = isset($cb['message']) && is_array($cb['message']) ? $cb['message'] : [];
        $chat = $message['chat'] ?? null;
        $chatId = '';
        if (is_array($chat) && isset($chat['id']) && is_scalar($chat['id'])) {
            $chatId = (string) $chat['id'];
        }

        if ($chatId !== ($this->settings['chat_id'] ?? '')) {
            return Response::json(['ok' => false], 403);
        }

        // Acknowledge the callback query immediately to stop the loading spinner
        $this->answerCallbackQuery($id);

        /** @var array{text: string, keyboard: array<string, mixed>|null}|null $result */
        $result = null;

        switch (true) {
            case $data === 'cmd_today':
                $result = $this->cmdToday();
                break;
            case $data === 'cmd_recent':
                $result = $this->cmdRecent();
                break;
            case $data === 'cmd_customers':
                $result = $this->cmdCustomers();
                break;
            case $data === 'cmd_disputes':
                $result = $this->cmdDisputes();
                break;
            case $data === 'cmd_refunds':
                $result = $this->cmdRefunds();
                break;
            case $data === 'cmd_gateways':
                $result = $this->cmdGateways();
                break;
            case $data === 'cmd_createlink_prompt':
                $result = [
                    'text' => "🔗 *Create Payment Link*\n\nTo create a reusable or one-off payment page, send a message in this format:\n`/createlink <amount> <currency> <title>`\n\n*Example*:\n`/createlink 1500 BDT Donation Box`",
                    'keyboard' => [
                        'inline_keyboard' => [
                            [['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']]
                        ]
                    ]
                ];
                break;
            case $data === 'cmd_createinvoice_prompt':
                $result = [
                    'text' => "📄 *Create Invoice*\n\nTo bill a customer directly, send a message in this format:\n`/createinvoice <customer_email> <amount> <currency> <description>`\n\n*Example*:\n`/createinvoice admin@example.com 4500 BDT SaaS Subscription`",
                    'keyboard' => [
                        'inline_keyboard' => [
                            [['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']]
                        ]
                    ]
                ];
                break;
            case str_starts_with($data, 'txn_details:'):
                $trxId = substr($data, 12);
                $result = $this->getTransactionDetailsResponse($trxId);
                break;
            case str_starts_with($data, 'txn_cust:'):
                $trxId = substr($data, 9);
                $result = $this->getTransactionCustomerResponse($trxId);
                break;
            case str_starts_with($data, 'txn_refund_prompt:'):
                $trxId = substr($data, 18);
                $result = $this->getTransactionRefundPromptResponse($trxId);
                break;
            case str_starts_with($data, 'txn_refund:'):
                $trxId = substr($data, 11);
                $result = $this->executeTransactionRefund($trxId);
                break;
            case $data === 'cmd_help':
            default:
                $result = [
                    'text' => "🤖 *OwnPay Bot - Help Menu*\n\n"
                        . "Here are the advanced commands you can execute:\n\n"
                        . "📊 `/today` - Today's financial metrics\n"
                        . "📋 `/recent` - Last 5 transactions status\n"
                        . "🔍 `/status <OP-ID>` - Search transaction status\n"
                        . "🔗 `/createlink <amount> <currency> <title>` - Generate pay link\n"
                        . "📄 `/createinvoice <email> <amount> <currency> <desc>` - Dynamic invoice\n"
                        . "👤 `/customers` - Customer stats & list\n"
                        . "🚨 `/disputes` - Open disputes summary\n"
                        . "💸 `/refunds` - Recent processed refunds\n"
                        . "🏦 `/gateways` - Real-time gateway status",
                    'keyboard' => $this->startKeyboard()
                ];
                break;
        }

        $this->sendMessage($result['text'], $chatId, $result['keyboard']);

        return Response::json(['ok' => true]);
    }

    /**
     * Resolves the primary Telegram welcome inline keyboard markup.
     *
     * @return array<string, array<int, array<int, array<string, string>>>> Inline keyboard mapping payload.
     */
    private function startKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 Today\'s Stats', 'callback_data' => 'cmd_today'],
                    ['text' => '📋 Recent Payments', 'callback_data' => 'cmd_recent']
                ],
                [
                    ['text' => '🔗 Create Link', 'callback_data' => 'cmd_createlink_prompt'],
                    ['text' => '📄 Create Invoice', 'callback_data' => 'cmd_createinvoice_prompt']
                ],
                [
                    ['text' => '👤 Customers', 'callback_data' => 'cmd_customers'],
                    ['text' => '🚨 Disputes', 'callback_data' => 'cmd_disputes']
                ],
                [
                    ['text' => '💸 Refunds', 'callback_data' => 'cmd_refunds'],
                    ['text' => '🏦 Gateways', 'callback_data' => 'cmd_gateways']
                ],
                [
                    ['text' => '❓ Help & Reference', 'callback_data' => 'cmd_help']
                ]
            ]
        ];
    }

    /**
     * Generates a new payment link programmatically via Telegram bot.
     *
     * @param string $text Raw incoming command string.
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdCreateLink(string $text): array
    {
        if (!$this->container) return ['text' => '⚠️ System not initialized', 'keyboard' => null];
        
        // Command format: /createlink <amount> <currency> <title>
        // e.g. /createlink 1500 BDT Donation Box
        $parts = explode(' ', $text, 4);
        $amountStr = trim($parts[1] ?? '');
        $currencyStr = strtoupper(trim($parts[2] ?? ''));
        $titleStr = trim($parts[3] ?? '');

        if ($amountStr === '' || $currencyStr === '' || $titleStr === '') {
            return [
                'text' => "⚠️ *Usage Guide*:\n`/createlink <amount> <currency> <title>`\n\n*Example*:\n`/createlink 1200 BDT Coffee Fund`",
                'keyboard' => null
            ];
        }

        if (!is_numeric($amountStr)) {
            return ['text' => "⚠️ *Error*: Amount must be a valid numeric value (e.g. 1500 or 99.99).", 'keyboard' => null];
        }

        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        // Find primary active merchant ID
        $merchant = $db->fetchOne("SELECT id FROM op_merchants WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$merchant) {
            return ['text' => '⚠️ No active merchant brand found in the system.', 'keyboard' => null];
        }
        $midVal = $merchant['id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;

        $svc = $this->container->get(\OwnPay\Service\Payment\PaymentLinkService::class);
        if (!$svc instanceof \OwnPay\Service\Payment\PaymentLinkService) {
            return ['text' => '⚠️ PaymentLinkService not found.', 'keyboard' => null];
        }

        try {
            $link = $svc->create($merchantId, [
                'title' => $titleStr,
                'amount' => $amountStr,
                'currency' => $currencyStr,
                'is_amount_fixed' => 1,
            ]);

            $baseUrl = getenv('APP_URL') ?: 'https://ownpay.test';
            $slugVal = $link['slug'] ?? '';
            $slug = is_string($slugVal) ? $slugVal : '';
            $checkoutUrl = $baseUrl . '/pay/' . $slug;

            return [
                'text' => "✅ *Payment Link Created!*\n\n"
                    . "🏷️ *Title*: {$titleStr}\n"
                    . "💰 *Amount*: {$currencyStr} {$amountStr}\n"
                    . "🔗 *Checkout URL*: [Open Pay Page]({$checkoutUrl})\n\n"
                    . "Click below to open checkout page:",
                'keyboard' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '🔗 Open Checkout', 'url' => $checkoutUrl]
                        ]
                    ]
                ]
            ];
        } catch (\Throwable $e) {
            return ['text' => "❌ *Error creating link*: " . $e->getMessage(), 'keyboard' => null];
        }
    }

    /**
     * Generates a new invoice dynamically via Telegram bot.
     *
     * @param string $text Raw incoming command string.
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdCreateInvoice(string $text): array
    {
        if (!$this->container) return ['text' => '⚠️ System not initialized', 'keyboard' => null];

        // Format: /createinvoice <customer_email> <amount> <currency> <description>
        // e.g. /createinvoice buyer@example.com 450 USD Consultation Fees
        $parts = explode(' ', $text, 5);
        $email = trim($parts[1] ?? '');
        $amountStr = trim($parts[2] ?? '');
        $currencyStr = strtoupper(trim($parts[3] ?? ''));
        $description = trim($parts[4] ?? '');

        if ($email === '' || $amountStr === '' || $currencyStr === '' || $description === '') {
            return [
                'text' => "⚠️ *Usage Guide*:\n`/createinvoice <email> <amount> <currency> <description>`\n\n*Example*:\n`/createinvoice buyer@example.com 250 USD Graphic Design`",
                'keyboard' => null
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['text' => '⚠️ *Error*: Invalid email format.', 'keyboard' => null];
        }

        if (!is_numeric($amountStr)) {
            return ['text' => '⚠️ *Error*: Amount must be numeric.', 'keyboard' => null];
        }

        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        // Find primary active merchant ID
        $merchant = $db->fetchOne("SELECT id FROM op_merchants WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$merchant) {
            return ['text' => '⚠️ No active merchant brand found.', 'keyboard' => null];
        }
        $midVal = $merchant['id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;

        $piiService = $this->container->get(\OwnPay\Service\Customer\CustomerPiiService::class);
        $invoiceService = $this->container->get(\OwnPay\Service\Payment\InvoiceService::class);

        if (!$piiService instanceof \OwnPay\Service\Customer\CustomerPiiService || !$invoiceService instanceof \OwnPay\Service\Payment\InvoiceService) {
            return ['text' => '⚠️ Core services unavailable', 'keyboard' => null];
        }

        try {
            // Find or create customer safely with encrypted PII columns
            $customer = $piiService->findByEmail($merchantId, $email);
            if (!$customer) {
                $customer = $piiService->create($merchantId, [
                    'email' => $email,
                    'name'  => 'Telegram Customer',
                ]);
            }

            $custId = $customer['id'] ?? null;
            if (!is_int($custId) && !is_string($custId)) {
                throw new \RuntimeException('Invalid customer ID');
            }

            $invoice = $invoiceService->create($merchantId, [
                'customer_id' => $custId,
                'currency' => $currencyStr,
                'items' => [
                    [
                        'description' => $description,
                        'quantity' => 1,
                        'unit_price' => $amountStr
                    ]
                ]
            ]);

            $baseUrl = getenv('APP_URL') ?: 'https://ownpay.test';
            $tokenVal = $invoice['token'] ?? '';
            $token = is_string($tokenVal) ? $tokenVal : '';
            $checkoutUrl = $baseUrl . '/invoice/' . $token;

            $invNumVal = $invoice['invoice_number'] ?? '';
            $invNum = is_string($invNumVal) ? $invNumVal : '';

            return [
                'text' => "✅ *Draft Invoice Generated!*\n\n"
                    . "🆔 *Invoice Number*: `{$invNum}`\n"
                    . "👤 *Customer Email*: {$email}\n"
                    . "💰 *Total Amount*: {$currencyStr} {$amountStr}\n"
                    . "📝 *Description*: {$description}\n\n"
                    . "Click below to review or checkout:",
                'keyboard' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '📄 View Invoice', 'url' => $checkoutUrl]
                        ]
                    ]
                ]
            ];
        } catch (\Throwable $e) {
            return ['text' => "❌ *Error creating invoice*: " . $e->getMessage(), 'keyboard' => null];
        }
    }

    /**
     * Resolves transaction status request.
     *
     * @param string $text Command arguments.
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot payload.
     */
    private function cmdStatus(string $text): array
    {
        $parts = explode(' ', $text, 2);
        $ref = trim($parts[1] ?? '');
        if ($ref === '') {
            return [
                'text' => "⚠️ *Usage Guide*:\n`/status <OP-ID>`\n\n*Example*:\n`/status OP-12345678`",
                'keyboard' => null
            ];
        }
        return $this->getTransactionDetailsResponse($ref);
    }

    /**
     * Helper to resolve the transaction detail payload.
     *
     * @param string $ref Transaction ID/Trx ID reference.
     * @return array{text: string, keyboard: array<string, mixed>|null} Target envelope.
     */
    private function getTransactionDetailsResponse(string $ref): array
    {
        if (!$this->container) return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        $txn = $db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!is_array($txn)) {
            return [
                'text' => "❌ *Transaction Not Found*:\nCould not find transaction with ID `{$ref}` in the database.",
                'keyboard' => [
                    'inline_keyboard' => [
                        [['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']]
                    ]
                ]
            ];
        }

        $trxId = is_scalar($txn['trx_id'] ?? null) ? (string) $txn['trx_id'] : '';
        $currency = is_scalar($txn['currency'] ?? null) ? (string) $txn['currency'] : '';
        $amount = is_scalar($txn['amount'] ?? null) ? (string) $txn['amount'] : '';
        $status = is_scalar($txn['status'] ?? null) ? (string) $txn['status'] : '';
        $gatewayStr = is_scalar($txn['gateway_slug'] ?? null) ? (string) $txn['gateway_slug'] : 'N/A';
        $createdAt = is_scalar($txn['created_at'] ?? null) ? (string) $txn['created_at'] : '';

        $icon = $status === 'completed' ? '✅' : ($status === 'pending' ? '⏳' : '❌');

        $msg = "📋 *Transaction Details*\n\n"
            . "🆔 *Ref*: `{$trxId}`\n"
            . "💰 *Amount*: {$currency} {$amount}\n"
            . "📊 *Status*: {$icon} {$status}\n"
            . "🏦 *Gateway*: {$gatewayStr}\n"
            . "🕐 *Created At*: {$createdAt}";

        $inlineKeyboard = [
            [
                ['text' => '🔄 Refresh', 'callback_data' => "txn_details:{$trxId}"],
                ['text' => '👤 Customer', 'callback_data' => "txn_cust:{$trxId}"]
            ]
        ];

        if ($status === 'completed') {
            $inlineKeyboard[] = [
                ['text' => '💸 Issue Refund', 'callback_data' => "txn_refund_prompt:{$trxId}"]
            ];
        }

        $inlineKeyboard[] = [
            ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
        ];

        return [
            'text' => $msg,
            'keyboard' => [
                'inline_keyboard' => $inlineKeyboard
            ]
        ];
    }

    /**
     * Helper to resolve customer PII securely for a transaction.
     *
     * @param string $ref Transaction ID reference.
     * @return array{text: string, keyboard: array<string, mixed>|null} Target envelope.
     */
    private function getTransactionCustomerResponse(string $ref): array
    {
        if (!$this->container) return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        $txn = $db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!is_array($txn)) {
            return ['text' => "❌ Transaction `{$ref}` not found.", 'keyboard' => null];
        }

        $midVal = $txn['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;
        $custIdVal = $txn['customer_id'] ?? null;
        $customerId = is_numeric($custIdVal) ? (int)$custIdVal : null;

        if ($customerId === null) {
            return [
                'text' => "👤 *Customer Details (Ref: `{$ref}`)*\n\nNo registered customer profile attached to this transaction.",
                'keyboard' => [
                    'inline_keyboard' => [
                        [['text' => '⬅️ Back to Transaction', 'callback_data' => "txn_details:{$ref}"]]
                    ]
                ]
            ];
        }

        $piiService = $this->container->get(\OwnPay\Service\Customer\CustomerPiiService::class);
        if (!$piiService instanceof \OwnPay\Service\Customer\CustomerPiiService) {
            return ['text' => '⚠️ Customer service unavailable', 'keyboard' => null];
        }

        $customer = $piiService->get($merchantId, $customerId);
        if (!$customer) {
            return ['text' => '❌ Customer record not found.', 'keyboard' => null];
        }

        $name = is_scalar($customer['name'] ?? null) ? (string)$customer['name'] : 'Anonymous';
        $email = is_scalar($customer['email'] ?? null) ? (string)$customer['email'] : 'N/A';
        $phone = is_scalar($customer['phone'] ?? null) ? (string)$customer['phone'] : 'N/A';

        $msg = "👤 *Customer Metadata (Ref: `{$ref}`)*\n\n"
            . "👤 *Name*: {$name}\n"
            . "📧 *Email*: {$email}\n"
            . "📞 *Phone*: {$phone}";

        return [
            'text' => $msg,
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '⬅️ Back to Transaction', 'callback_data' => "txn_details:{$ref}"],
                        ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                    ]
                ]
            ]
        ];
    }

    /**
     * Helper to show a confirmation layout prior to executing refund.
     *
     * @param string $ref Transaction ID reference.
     * @return array{text: string, keyboard: array<string, mixed>|null} Target envelope.
     */
    private function getTransactionRefundPromptResponse(string $ref): array
    {
        if (!$this->container) return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        $txn = $db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!is_array($txn)) {
            return ['text' => "❌ Transaction `{$ref}` not found.", 'keyboard' => null];
        }

        $currency = is_scalar($txn['currency'] ?? null) ? (string) $txn['currency'] : '';
        $amount = is_scalar($txn['amount'] ?? null) ? (string) $txn['amount'] : '';

        $msg = "💸 *Refund Request: `{$ref}`*\n\n"
            . "Are you sure you want to issue a **Full Refund** for this transaction?\n\n"
            . "💰 *Refund Amount*: {$currency} {$amount}\n\n"
            . "⚠️ *Warning*: This operation will interact with the downstream payment gateway and post debit/credit entries to the ledger accounts.";

        return [
            'text' => $msg,
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '💸 Confirm Full Refund', 'callback_data' => "txn_refund:{$ref}"],
                    ],
                    [
                        ['text' => '❌ Cancel', 'callback_data' => "txn_details:{$ref}"]
                    ]
                ]
            ]
        ];
    }

    /**
     * Helper to execute a full transaction refund.
     *
     * @param string $ref Transaction ID reference.
     * @return array{text: string, keyboard: array<string, mixed>|null} Target envelope.
     */
    private function executeTransactionRefund(string $ref): array
    {
        if (!$this->container) return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        $txn = $db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!is_array($txn)) {
            return ['text' => "❌ Transaction `{$ref}` not found.", 'keyboard' => null];
        }

        $midVal = $txn['merchant_id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;
        $txnIdVal = $txn['id'] ?? null;
        $transactionId = is_numeric($txnIdVal) ? (int)$txnIdVal : 0;

        $refundService = $this->container->get(\OwnPay\Service\Payment\RefundService::class);
        if (!$refundService instanceof \OwnPay\Service\Payment\RefundService) {
            return ['text' => '⚠️ Refund service not available.', 'keyboard' => null];
        }

        try {
            $refundService->create($merchantId, [
                'transaction_id' => $transactionId,
                'reason' => 'Refund requested via Telegram Administrative Bot'
            ]);

            return [
                'text' => "✅ *Refund Processed Successfully!*\n\n"
                    . "Transaction `{$ref}` has been fully refunded. The ledger accounts have been adjusted and balance compliance has been verified.",
                'keyboard' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '🔎 View Details', 'callback_data' => "txn_details:{$ref}"],
                            ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                        ]
                    ]
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'text' => "❌ *Refund Failed*:\n" . $e->getMessage(),
                'keyboard' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '⬅️ Back to Transaction', 'callback_data' => "txn_details:{$ref}"]
                        ]
                    ]
                ]
            ];
        }
    }

    /**
     * Resolves financial summary metrics for today.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdToday(): array
    {
        if (!$this->container) return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        }

        $stats = $db->fetchOne(
            "SELECT COUNT(*) as total,
                    COUNT(CASE WHEN status='completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN status='pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status='failed' THEN 1 END) as failed,
                    COUNT(CASE WHEN status='refunded' THEN 1 END) as refunded
             FROM op_transactions 
             WHERE DATE(created_at) = CURDATE()"
        );

        if (!is_array($stats)) {
            $stats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'refunded' => 0];
        }

        $totalVal = $stats['total'] ?? null;
        $compVal = $stats['completed'] ?? null;
        $pendVal = $stats['pending'] ?? null;
        $failVal = $stats['failed'] ?? null;
        $refVal = $stats['refunded'] ?? null;

        $total = is_numeric($totalVal) ? (int)$totalVal : 0;
        $completed = is_numeric($compVal) ? (int)$compVal : 0;
        $pending = is_numeric($pendVal) ? (int)$pendVal : 0;
        $failed = is_numeric($failVal) ? (int)$failVal : 0;
        $refunded = is_numeric($refVal) ? (int)$refVal : 0;

        // Fetch revenue grouped by currency to support multiple currencies
        $revenueRows = $db->fetchAll(
            "SELECT currency, COALESCE(SUM(amount), 0) as revenue 
             FROM op_transactions 
             WHERE status = 'completed' AND DATE(created_at) = CURDATE() 
             GROUP BY currency"
        );

        $revenueStr = '';
        if (empty($revenueRows)) {
            $revenueStr = "💰 *Revenue*: BDT 0.00";
        } else {
            $lines = [];
            foreach ($revenueRows as $row) {
                $cur = is_scalar($row['currency'] ?? null) ? (string)$row['currency'] : 'BDT';
                $rev = is_scalar($row['revenue'] ?? null) ? (string)$row['revenue'] : '0.00';
                $lines[] = "💰 *Revenue ({$cur})*: {$rev}";
            }
            $revenueStr = implode("\n", $lines);
        }

        $msg = "📊 *Today's Financial Summary*\n"
            . "📅 Date: " . date('Y-m-d') . "\n\n"
            . "📦 *Total Transactions*: {$total}\n"
            . "✅ *Completed*: {$completed}\n"
            . "⏳ *Pending*: {$pending}\n"
            . "❌ *Failed*: {$failed}\n"
            . "💸 *Refunded*: {$refunded}\n\n"
            . $revenueStr;

        return [
            'text' => $msg,
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Refresh', 'callback_data' => 'cmd_today'],
                        ['text' => '📋 Recent', 'callback_data' => 'cmd_recent']
                    ],
                    [
                        ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                    ]
                ]
            ]
        ];
    }

    /**
     * Lists the last 5 transactions status.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdRecent(): array
    {
        if (!$this->container) return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Not initialized', 'keyboard' => null];
        }

        $txns = $db->fetchAll("SELECT trx_id, amount, currency, status FROM op_transactions ORDER BY created_at DESC LIMIT 5");
        if (empty($txns)) {
            return [
                'text' => '📭 *No transactions found* in the system.',
                'keyboard' => [
                    'inline_keyboard' => [
                        [['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']]
                    ]
                ]
            ];
        }

        $lines = ["📋 *Recent Transactions (Last 5)*\n"];
        $buttons = [];

        foreach ($txns as $t) {
            $status = is_scalar($t['status'] ?? null) ? (string) $t['status'] : '';
            $icon = $status === 'completed' ? '✅' : ($status === 'pending' ? '⏳' : '❌');
            $trxId = is_scalar($t['trx_id'] ?? null) ? (string) $t['trx_id'] : '';
            $currency = is_scalar($t['currency'] ?? null) ? (string) $t['currency'] : '';
            $amount = is_scalar($t['amount'] ?? null) ? (string) $t['amount'] : '';

            $lines[] = "{$icon} `{$trxId}` - {$currency} {$amount}";

            $buttons[] = [
                ['text' => "🔍 Details: {$trxId}", 'callback_data' => "txn_details:{$trxId}"]
            ];
        }

        $buttons[] = [
            ['text' => '🔄 Refresh', 'callback_data' => 'cmd_recent'],
            ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
        ];

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                'inline_keyboard' => $buttons
            ]
        ];
    }

    /**
     * Resolves total system customer metrics and list.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdCustomers(): array
    {
        if (!$this->container) return ['text' => '⚠️ System not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        // Find primary active merchant ID
        $merchant = $db->fetchOne("SELECT id FROM op_merchants WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$merchant) {
            return ['text' => '⚠️ No active merchant brand found.', 'keyboard' => null];
        }
        $midVal = $merchant['id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;

        $piiService = $this->container->get(\OwnPay\Service\Customer\CustomerPiiService::class);
        if (!$piiService instanceof \OwnPay\Service\Customer\CustomerPiiService) {
            return ['text' => '⚠️ Customer service unavailable', 'keyboard' => null];
        }

        // Get total customer count today vs total
        $cntRow = $db->fetchOne(
            "SELECT COUNT(*) as total, COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today 
             FROM op_customers WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
        if (!is_array($cntRow)) {
            $cntRow = ['total' => 0, 'today' => 0];
        }
        $total = is_scalar($cntRow['total'] ?? null) ? (int)$cntRow['total'] : 0;
        $today = is_scalar($cntRow['today'] ?? null) ? (int)$cntRow['today'] : 0;

        // Fetch last 3 customers
        $res = $piiService->list($merchantId, 1, 3);
        $items = $res['items'];

        $lines = ["👤 *Customer Summary*\n"
            . "👥 *Total Customers*: {$total}\n"
            . "🆕 *New Today*: {$today}\n"
            . "\n"
            . "📋 *Recent Customers*:"];

        if (empty($items)) {
            $lines[] = "📭 No customer records found.";
        } else {
            foreach ($items as $item) {
                $name = is_scalar($item['name'] ?? null) ? (string)$item['name'] : 'Anonymous';
                $email = is_scalar($item['email_masked'] ?? null) ? (string)$item['email_masked'] : 'N/A';
                $lines[] = "• *{$name}* ({$email})";
            }
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Refresh', 'callback_data' => 'cmd_customers'],
                        ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                    ]
                ]
            ]
        ];
    }

    /**
     * Resolves all open/active payment disputes.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdDisputes(): array
    {
        if (!$this->container) return ['text' => '⚠️ System not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        // Find primary active merchant ID
        $merchant = $db->fetchOne("SELECT id FROM op_merchants WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$merchant) {
            return ['text' => '⚠️ No active merchant brand found.', 'keyboard' => null];
        }
        $midVal = $merchant['id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;

        $openCount = $db->fetchColumn(
            "SELECT COUNT(*) FROM op_disputes WHERE merchant_id = :mid AND status IN ('open', 'under_review')",
            ['mid' => $merchantId]
        );
        $count = is_numeric($openCount) ? (int)$openCount : 0;

        $disputes = $db->fetchAll(
            "SELECT d.*, t.trx_id, t.currency 
             FROM op_disputes d 
             JOIN op_transactions t ON d.transaction_id = t.id 
             WHERE d.merchant_id = :mid 
             ORDER BY d.created_at DESC LIMIT 5",
            ['mid' => $merchantId]
        );

        $lines = ["🚨 *Disputes Summary*\n"
            . "⚠️ *Open/Under Review Disputes*: {$count}\n"
            . "\n"
            . "📋 *Recent Disputes (Last 5)*:"];

        if (empty($disputes)) {
            $lines[] = "📭 No disputes found.";
        } else {
            foreach ($disputes as $d) {
                $status = is_scalar($d['status'] ?? null) ? (string)$d['status'] : 'open';
                $trxId = is_scalar($d['trx_id'] ?? null) ? (string)$d['trx_id'] : 'N/A';
                $amount = is_scalar($d['amount'] ?? null) ? (string)$d['amount'] : '0.00';
                $currency = is_scalar($d['currency'] ?? null) ? (string)$d['currency'] : 'BDT';
                $reason = is_scalar($d['reason'] ?? null) ? (string)$d['reason'] : 'None';

                $icon = $status === 'open' ? '🔴' : ($status === 'under_review' ? '🟡' : '⚪');
                $lines[] = "{$icon} `{$trxId}` - *{$currency} {$amount}* ({$status})\n   _Reason_: {$reason}";
            }
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Refresh', 'callback_data' => 'cmd_disputes'],
                        ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                    ]
                ]
            ]
        ];
    }

    /**
     * Resolves the last 5 refunds processed in the system.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdRefunds(): array
    {
        if (!$this->container) return ['text' => '⚠️ System not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        // Find primary active merchant ID
        $merchant = $db->fetchOne("SELECT id FROM op_merchants WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$merchant) {
            return ['text' => '⚠️ No active merchant brand found.', 'keyboard' => null];
        }
        $midVal = $merchant['id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;

        $refunds = $db->fetchAll(
            "SELECT r.*, t.trx_id, t.currency 
             FROM op_refunds r 
             JOIN op_transactions t ON r.transaction_id = t.id 
             WHERE r.merchant_id = :mid 
             ORDER BY r.created_at DESC LIMIT 5",
            ['mid' => $merchantId]
        );

        $lines = ["💸 *Recent Refunds (Last 5)*\n"];

        if (empty($refunds)) {
            $lines[] = "📭 No refunds found.";
        } else {
            foreach ($refunds as $r) {
                $status = is_scalar($r['status'] ?? null) ? (string)$r['status'] : 'pending';
                $trxId = is_scalar($r['trx_id'] ?? null) ? (string)$r['trx_id'] : 'N/A';
                $amount = is_scalar($r['amount'] ?? null) ? (string)$r['amount'] : '0.00';
                $currency = is_scalar($r['currency'] ?? null) ? (string)$r['currency'] : 'BDT';
                $reason = is_scalar($r['reason'] ?? null) ? (string)$r['reason'] : 'None';

                $icon = $status === 'completed' ? '✅' : ($status === 'pending' ? '⏳' : '❌');
                $lines[] = "{$icon} `{$trxId}` - *{$currency} {$amount}* ({$status})\n   _Reason_: {$reason}";
            }
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Refresh', 'callback_data' => 'cmd_refunds'],
                        ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                    ]
                ]
            ]
        ];
    }

    /**
     * Resolves configured gateways and their status.
     *
     * @return array{text: string, keyboard: array<string, mixed>|null} Resolved bot response payload.
     */
    private function cmdGateways(): array
    {
        if (!$this->container) return ['text' => '⚠️ System not initialized', 'keyboard' => null];
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return ['text' => '⚠️ Database unavailable', 'keyboard' => null];
        }

        // Find primary active merchant ID
        $merchant = $db->fetchOne("SELECT id FROM op_merchants WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$merchant) {
            return ['text' => '⚠️ No active merchant brand found.', 'keyboard' => null];
        }
        $midVal = $merchant['id'] ?? null;
        $merchantId = is_numeric($midVal) ? (int)$midVal : 1;

        $gcRepo = $this->container->get(\OwnPay\Repository\GatewayConfigRepository::class);
        if (!$gcRepo instanceof \OwnPay\Repository\GatewayConfigRepository) {
            return ['text' => '⚠️ Gateway config repository unavailable', 'keyboard' => null];
        }

        $list = $gcRepo->forTenant($merchantId)->listActive();

        $lines = ["🏦 *Active Gateways Overview*\n"
            . "These are the active payment methods configured for this brand:\n"];

        if (empty($list)) {
            $lines[] = "📭 No active gateways configured.";
        } else {
            foreach ($list as $gw) {
                $name = is_scalar($gw['name'] ?? null) ? (string)$gw['name'] : 'Unknown';
                $slug = is_scalar($gw['slug'] ?? null) ? (string)$gw['slug'] : 'N/A';
                $type = is_scalar($gw['type'] ?? null) ? (string)$gw['type'] : 'builtin';
                $mode = is_scalar($gw['mode'] ?? null) ? (string)$gw['mode'] : 'live';

                $lines[] = "• ✅ *{$name}* (`{$slug}`)\n   _Type_: {$type} | _Mode_: {$mode}";
            }
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Refresh', 'callback_data' => 'cmd_gateways'],
                        ['text' => '📂 Main Menu', 'callback_data' => 'cmd_help']
                    ]
                ]
            ]
        ];
    }

    /**
     * Answers callback query to prevent Telegram loading spinners from freezing.
     *
     * @param string $callbackQueryId The unique identifier of the callback query.
     * @return void
     */
    private function answerCallbackQuery(string $callbackQueryId): void
    {
        $token = $this->settings['bot_token'] ?? '';
        if ($token === '' || $callbackQueryId === '') return;

        $ch = curl_init("https://api.telegram.org/bot{$token}/answerCallbackQuery");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => (string) json_encode([
                'callback_query_id' => $callbackQueryId,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Dispatcher method carrying standard text strings and inline keyboard arrays.
     *
     * @param string $text Outbound text content.
     * @param string|null $chatId Target chat identifier.
     * @param array<string, mixed>|null $keyboard Optional inline keyboard layout parameters.
     * @param array<string, string>|null $customSettings Optional dynamic settings override.
     * @return void
     */
    private function sendMessage(string $text, ?string $chatId = null, ?array $keyboard = null, ?array $customSettings = null): void
    {
        $settings = $customSettings ?? $this->settings;
        $token = $settings['bot_token'] ?? '';
        $chat = $chatId ?? ($settings['chat_id'] ?? '');
        if ($token === '' || $chat === '') return;

        $payload = [
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Set the Telegram webhook dynamically when plugin settings are saved.
     *
     * @param string $slug The updated plugin slug identifier.
     * @param array<string, mixed> $settings The newly saved configurations.
     * @param int|null $brandId Optional brand context identifier.
     * @return void
     */
    public function onSettingsSaved(string $slug, array $settings, ?int $brandId = null): void
    {
        if ($slug !== 'telegram-bot') return;
        if (!$this->container) return;

        // Force reload settings locally for immediate effect
        $this->settings = [];
        foreach ($settings as $k => $v) {
            $this->settings[(string)$k] = is_scalar($v) ? (string)$v : '';
        }

        $token = $this->settings['bot_token'] ?? '';
        if ($token === '') return;

        $urlSvc = $this->container->get(\OwnPay\Service\Domain\DomainUrlService::class);
        if ($urlSvc instanceof \OwnPay\Service\Domain\DomainUrlService) {
            // Resolve base URL for primary merchant
            $baseUrl = $urlSvc->resolveBaseUrl(1);
            $webhookUrl = $baseUrl . '/plugins/telegram-bot/webhook';

            // Register webhook on Telegram
            $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => (string) json_encode([
                    'url' => $webhookUrl,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    /**
     * Resolves settings dynamically, cascading from brand-specific override to global fallback.
     *
     * @param int|null $merchantId Optional merchant identifier.
     * @return array<string, string> The resolved settings mapping.
     */
    private function getSettingsForMerchant(?int $merchantId): array
    {
        if (!$this->container) return $this->settings;
        $repo = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
        if (!$repo instanceof \OwnPay\Repository\SettingsRepository) {
            return $this->settings;
        }

        if ($merchantId !== null && $merchantId > 0) {
            return $repo->getGroupScoped('plugin.telegram-bot', $merchantId);
        }

        return $repo->getGroup('plugin.telegram-bot');
    }
}
