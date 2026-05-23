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
        if (empty($this->settings['alert_on_success'])) return;
        $this->sendMessage($this->formatAlert('✅ Payment Received', $txn));
    }

    /** @param array<string, mixed> $txn */
    public function onFailed(array $txn): void
    {
        if (empty($this->settings['alert_on_failure'])) return;
        $this->sendMessage($this->formatAlert('❌ Payment Failed', $txn));
    }

    /** @param array<string, mixed> $txn */
    private function formatAlert(string $title, array $txn): string
    {
        $amount = is_scalar($txn['amount'] ?? null) ? (string) $txn['amount'] : '0.00';
        $currency = is_scalar($txn['currency'] ?? null) ? (string) $txn['currency'] : 'BDT';
        $trxId = is_scalar($txn['trx_id'] ?? null) ? (string) $txn['trx_id'] : 'N/A';
        $gatewayStr = is_scalar($txn['gateway'] ?? null) ? (string) $txn['gateway'] : 'N/A';
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
     * Webhook handler — /plugins/telegram-bot/webhook
     */
    public function handleWebhook(Request $req): Response
    {
        $body = $req->json();
        if (!is_array($body)) {
            return Response::json(['ok' => false], 400);
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

        $reply = match (true) {
            str_starts_with($text, '/status')  => $this->cmdStatus($text),
            str_starts_with($text, '/today')   => $this->cmdToday(),
            str_starts_with($text, '/recent')  => $this->cmdRecent(),
            str_starts_with($text, '/start')   => "🤖 Own Pay Bot\n\nCommands:\n/status OP-ID — Check transaction\n/today — Today's stats\n/recent — Last 5 transactions",
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
        if ($ref === '') return '⚠️ Usage: /status OP-ID';
        if (!$this->container) return '⚠️ Not initialized';

        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return '⚠️ Not initialized';
        }
        $txn = $db->fetchOne("SELECT trx_id, amount, currency, status, gateway, created_at FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!is_array($txn)) return "❌ Transaction `{$ref}` not found.";

        $trxId = is_scalar($txn['trx_id'] ?? null) ? (string) $txn['trx_id'] : '';
        $currency = is_scalar($txn['currency'] ?? null) ? (string) $txn['currency'] : '';
        $amount = is_scalar($txn['amount'] ?? null) ? (string) $txn['amount'] : '';
        $status = is_scalar($txn['status'] ?? null) ? (string) $txn['status'] : '';
        $gatewayStr = is_scalar($txn['gateway'] ?? null) ? (string) $txn['gateway'] : '';
        $createdAt = is_scalar($txn['created_at'] ?? null) ? (string) $txn['created_at'] : '';

        return "📋 Transaction Status\n\n🆔 `{$trxId}`\n💰 {$currency} {$amount}\n📊 Status: {$status}\n🏦 Gateway: {$gatewayStr}\n🕐 {$createdAt}";
    }

    private function cmdToday(): string
    {
        if (!$this->container) return '⚠️ Not initialized';
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return '⚠️ Not initialized';
        }
        $stats = $db->fetchOne(
            "SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as revenue, COUNT(CASE WHEN status='completed' THEN 1 END) as completed, COUNT(CASE WHEN status='pending' THEN 1 END) as pending FROM op_transactions WHERE DATE(created_at) = CURDATE()"
        );
        if (!is_array($stats)) {
            $stats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'revenue' => '0.00'];
        }
        $total = is_scalar($stats['total'] ?? null) ? (string) $stats['total'] : '0';
        $completed = is_scalar($stats['completed'] ?? null) ? (string) $stats['completed'] : '0';
        $pending = is_scalar($stats['pending'] ?? null) ? (string) $stats['pending'] : '0';
        $revenue = is_scalar($stats['revenue'] ?? null) ? (string) $stats['revenue'] : '0.00';
        return "📊 Today's Summary\n\n📦 Total: {$total}\n✅ Completed: {$completed}\n⏳ Pending: {$pending}\n💰 Revenue: BDT {$revenue}";
    }

    private function cmdRecent(): string
    {
        if (!$this->container) return '⚠️ Not initialized';
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return '⚠️ Not initialized';
        }
        $txns = $db->fetchAll("SELECT trx_id, amount, currency, status FROM op_transactions ORDER BY created_at DESC LIMIT 5");
        if (empty($txns)) return '📭 No recent transactions.';

        $lines = ["📋 Last 5 Transactions\n"];
        foreach ($txns as $t) {
            $status = is_scalar($t['status'] ?? null) ? (string) $t['status'] : '';
            $icon = $status === 'completed' ? '✅' : ($status === 'pending' ? '⏳' : '❌');
            $trxId = is_scalar($t['trx_id'] ?? null) ? (string) $t['trx_id'] : '';
            $currency = is_scalar($t['currency'] ?? null) ? (string) $t['currency'] : '';
            $amount = is_scalar($t['amount'] ?? null) ? (string) $t['amount'] : '';
            $lines[] = "{$icon} `{$trxId}` — {$currency} {$amount}";
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
            CURLOPT_POSTFIELDS => (string) json_encode([
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
