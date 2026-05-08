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
 * Telegram Bot Addon — transaction alerts + commands.
 * senior-security: Bot token stored in DB, never logged.
 */
final class Plugin implements PluginInterface
{
    private array $settings = [];
    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Telegram Bot',
            'slug'        => 'telegram-bot',
            'version'     => '1.0.0',
            'description' => 'Telegram bot for transaction alerts and admin commands.',
            'author'      => 'Own Pay',
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
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            $this->settings = $repo->getGroup('plugin.telegram-bot');
        }
    }

    public function deactivate(Container $container): void {}

    public function uninstall(Container $container): void
    {
        if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
            $repo = $container->get(\OwnPay\Repository\SettingsRepository::class);
            $repo->deleteGroup('plugin.telegram-bot');
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

    public function onCompleted(array $txn): void
    {
        if (empty($this->settings['alert_on_success']) || $this->settings['alert_on_success'] === '0') return;
        $this->sendMessage($this->formatAlert('✅ Payment Received', $txn));
    }

    public function onFailed(array $txn): void
    {
        if (empty($this->settings['alert_on_failure']) || $this->settings['alert_on_failure'] === '0') return;
        $this->sendMessage($this->formatAlert('❌ Payment Failed', $txn));
    }

    private function formatAlert(string $title, array $txn): string
    {
        $amount = $txn['amount'] ?? '0.00';
        $currency = $txn['currency'] ?? 'BDT';
        $trxId = $txn['trx_id'] ?? 'N/A';
        $gateway = $txn['gateway'] ?? 'N/A';
        $customer = $txn['customer_name'] ?? ($txn['customer_email'] ?? 'Anonymous');

        return "{$title}\n\n"
            . "💰 Amount: {$currency} {$amount}\n"
            . "🆔 Ref: `{$trxId}`\n"
            . "🏦 Gateway: {$gateway}\n"
            . "👤 Customer: {$customer}\n"
            . "🕐 " . date('Y-m-d H:i:s');
    }

    /**
     * Webhook handler — /plugins/telegram-bot/webhook
     */
    public function handleWebhook(Request $req): Response
    {
        $body = $req->jsonBody();
        $message = $body['message'] ?? [];
        $text = trim($message['text'] ?? '');
        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($chatId !== ($this->settings['chat_id'] ?? '')) {
            return Response::json(['ok' => false], 403);
        }

        $reply = match (true) {
            str_starts_with($text, '/status')  => $this->cmdStatus($text),
            str_starts_with($text, '/today')   => $this->cmdToday(),
            str_starts_with($text, '/recent')  => $this->cmdRecent(),
            str_starts_with($text, '/start')   => "🤖 Own Pay Bot\n\nCommands:\n/status TXN-ID — Check transaction\n/today — Today's stats\n/recent — Last 5 transactions",
            default => null,
        };

        if ($reply !== null) {
            $this->sendMessage($reply, $chatId);
        }

        return Response::json(['ok' => true]);
    }

    private function cmdStatus(string $text): string
    {
        $parts = explode(' ', $text, 2);
        $ref = trim($parts[1] ?? '');
        if ($ref === '') return '⚠️ Usage: /status TXN-ID';
        if (!$this->container) return '⚠️ Not initialized';

        $db = $this->container->get(\OwnPay\Core\Database::class);
        $txn = $db->fetchOne("SELECT trx_id, amount, currency, status, gateway, created_at FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!$txn) return "❌ Transaction `{$ref}` not found.";

        return "📋 Transaction Status\n\n🆔 `{$txn['trx_id']}`\n💰 {$txn['currency']} {$txn['amount']}\n📊 Status: {$txn['status']}\n🏦 Gateway: {$txn['gateway']}\n🕐 {$txn['created_at']}";
    }

    private function cmdToday(): string
    {
        if (!$this->container) return '⚠️ Not initialized';
        $db = $this->container->get(\OwnPay\Core\Database::class);
        $stats = $db->fetchOne(
            "SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as revenue, COUNT(CASE WHEN status='completed' THEN 1 END) as completed, COUNT(CASE WHEN status='pending' THEN 1 END) as pending FROM op_transactions WHERE DATE(created_at) = CURDATE()"
        );
        return "📊 Today's Summary\n\n📦 Total: {$stats['total']}\n✅ Completed: {$stats['completed']}\n⏳ Pending: {$stats['pending']}\n💰 Revenue: BDT {$stats['revenue']}";
    }

    private function cmdRecent(): string
    {
        if (!$this->container) return '⚠️ Not initialized';
        $db = $this->container->get(\OwnPay\Core\Database::class);
        $txns = $db->fetchAll("SELECT trx_id, amount, currency, status FROM op_transactions ORDER BY created_at DESC LIMIT 5");
        if (empty($txns)) return '📭 No recent transactions.';

        $lines = ["📋 Last 5 Transactions\n"];
        foreach ($txns as $t) {
            $icon = $t['status'] === 'completed' ? '✅' : ($t['status'] === 'pending' ? '⏳' : '❌');
            $lines[] = "{$icon} `{$t['trx_id']}` — {$t['currency']} {$t['amount']}";
        }
        return implode("\n", $lines);
    }

    private function sendMessage(string $text, ?string $chatId = null): void
    {
        $token = $this->settings['bot_token'] ?? '';
        $chat = $chatId ?? ($this->settings['chat_id'] ?? '');
        if ($token === '' || $chat === '') return;

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chat,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
