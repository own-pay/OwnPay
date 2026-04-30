<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\TelegramBot;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;
use OwnPay\Core\Logger;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Telegram Bot Addon — transaction alerts + commands.
 * senior-security: Bot token stored in DB, never logged.
 */
final class Plugin implements PluginInterface
{
    private array $settings = [];
    private ?Logger $logger = null;
    private ?Database $db = null;

    public function register(EventManager $events): void
    {
        $events->addAction('payment.transaction.completed', [$this, 'onCompleted'], 20);
        $events->addAction('payment.transaction.failed', [$this, 'onFailed'], 20);
    }

    public function setSettings(array $s): void { $this->settings = $s; }
    public function setLogger(Logger $l): void { $this->logger = $l; }
    public function setDatabase(Database $db): void { $this->db = $db; }

    public function onCompleted(array $txn): void
    {
        if (!($this->settings['alert_on_success'] ?? true)) return;
        $this->sendMessage($this->formatAlert('✅ Payment Received', $txn));
    }

    public function onFailed(array $txn): void
    {
        if (!($this->settings['alert_on_failure'] ?? true)) return;
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
     * Processes commands: /status, /today, /recent
     */
    public function handleWebhook(Request $req): Response
    {
        $body = $req->jsonBody();
        $message = $body['message'] ?? [];
        $text = trim($message['text'] ?? '');
        $chatId = (string) ($message['chat']['id'] ?? '');

        // Verify chat ID matches configured
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

        if (!$this->db) return '⚠️ Database not available';
        $txn = $this->db->fetchOne("SELECT trx_id, amount, currency, status, gateway, created_at FROM op_transactions WHERE trx_id = :ref", ['ref' => $ref]);
        if (!$txn) return "❌ Transaction `{$ref}` not found.";

        return "📋 Transaction Status\n\n"
            . "🆔 `{$txn['trx_id']}`\n"
            . "💰 {$txn['currency']} {$txn['amount']}\n"
            . "📊 Status: {$txn['status']}\n"
            . "🏦 Gateway: {$txn['gateway']}\n"
            . "🕐 {$txn['created_at']}";
    }

    private function cmdToday(): string
    {
        if (!$this->db) return '⚠️ Database not available';
        $stats = $this->db->fetchOne(
            "SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as revenue, COUNT(CASE WHEN status='completed' THEN 1 END) as completed, COUNT(CASE WHEN status='pending' THEN 1 END) as pending FROM op_transactions WHERE DATE(created_at) = CURDATE()"
        );

        return "📊 Today's Summary\n\n"
            . "📦 Total: {$stats['total']}\n"
            . "✅ Completed: {$stats['completed']}\n"
            . "⏳ Pending: {$stats['pending']}\n"
            . "💰 Revenue: BDT {$stats['revenue']}";
    }

    private function cmdRecent(): string
    {
        if (!$this->db) return '⚠️ Database not available';
        $txns = $this->db->fetchAll("SELECT trx_id, amount, currency, status, created_at FROM op_transactions ORDER BY created_at DESC LIMIT 5");
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

    public function getInfo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/manifest.json'), true) ?: [];
    }
}
