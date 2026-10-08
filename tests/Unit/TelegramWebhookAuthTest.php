<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\SettingsRepository;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/addons/telegram-bot/Plugin.php';

use OwnPay\Modules\Addons\TelegramBot\Plugin;

/**
 * The Telegram webhook is mounted on the public `api-public` middleware group,
 * so it must authenticate the *sender* - not just compare a request-supplied
 * chat ID against the configured one. Telegram echoes the webhook
 * `secret_token` back on every delivery in X-Telegram-Bot-Api-Secret-Token.
 *
 * @see https://github.com/own-pay/OwnPay/issues/616
 */
final class TelegramWebhookAuthTest extends TestCase
{
    private const SECRET   = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const CHAT_ID  = '987654321';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Builds a booted plugin whose settings group is exactly $settings.
     *
     * DomainUrlService is bound to a non-matching value so boot()'s setWebhook
     * attempt short-circuits on its type guard - no outbound request is made
     * from a unit test.
     *
     * @param array<string, string> $settings
     */
    private function pluginWithSettings(array $settings): Plugin
    {
        $container = new Container();
        $db = $this->createMock(Database::class);

        $rows = [];
        foreach ($settings as $key => $value) {
            $rows[] = ['key_name' => $key, 'value' => $value];
        }
        $db->method('fetchAll')->willReturn($rows);

        $container->instance(Database::class, $db);
        $container->instance(SettingsRepository::class, new SettingsRepository($db));
        $container->instance(\OwnPay\Service\Domain\DomainUrlService::class, new \stdClass());

        $plugin = new Plugin();
        $plugin->boot($container);

        return $plugin;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function webhookRequest(array $payload, ?string $secretHeader): Request
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/plugins/telegram-bot/webhook',
        ];
        if ($secretHeader !== null) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secretHeader;
        }

        return new Request([], [], $server, [], [], (string) json_encode($payload));
    }

    /**
     * A message whose command matches no handler, so handle() returns 200
     * without dispatching anything or calling sendMessage(). Keeps these tests
     * free of outbound HTTPS traffic.
     *
     * @return array<string, mixed>
     */
    private function startMessage(int $chatId): array
    {
        return ['message' => ['chat' => ['id' => $chatId], 'text' => '/noop']];
    }

    private function configuredPlugin(): Plugin
    {
        return $this->pluginWithSettings([
            'bot_token'      => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'chat_id'        => self::CHAT_ID,
            'webhook_secret' => self::SECRET,
        ]);
    }

    public function testCorrectSecretIsAccepted(): void
    {
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest($this->startMessage((int) self::CHAT_ID), self::SECRET)
        );

        $this->assertSame(200, $res->getStatusCode());
    }

    public function testMissingSecretHeaderIsRejectedEvenWithCorrectChatId(): void
    {
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest($this->startMessage((int) self::CHAT_ID), null)
        );

        $this->assertSame(403, $res->getStatusCode());
    }

    public function testWrongSecretIsRejectedEvenWithCorrectChatId(): void
    {
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest($this->startMessage((int) self::CHAT_ID), 'forged')
        );

        $this->assertSame(403, $res->getStatusCode());
    }

    public function testForgedRequestWithCorrectChatIdCannotReachAnyCommand(): void
    {
        // The exact scenario from the finding: an attacker who learned the chat
        // ID could previously read financial data with no further proof. /noop
        // matches no handler, so the 403 below proves the rejection happens
        // before any command dispatch - and before sendMessage() would fire.
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest(
                ['message' => ['chat' => ['id' => (int) self::CHAT_ID], 'text' => '/noop']],
                null
            )
        );

        $this->assertSame(403, $res->getStatusCode());
    }

    public function testForgedRequestCannotCreatePaymentLink(): void
    {
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest(
                ['message' => ['chat' => ['id' => (int) self::CHAT_ID], 'text' => '/createlink 1500 BDT Box']],
                'wrong-secret'
            )
        );

        $this->assertSame(403, $res->getStatusCode());
    }

    public function testForgedCallbackQueryIsRejected(): void
    {
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest(
                ['callback_query' => ['id' => 'cb-1', 'data' => 'cmd_customers', 'message' => ['chat' => ['id' => (int) self::CHAT_ID]]]],
                null
            )
        );

        $this->assertSame(403, $res->getStatusCode());
    }

    public function testUnconfiguredSecretFailsClosed(): void
    {
        $plugin = $this->pluginWithSettings([
            'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'chat_id'   => self::CHAT_ID,
        ]);

        $res = $plugin->handleWebhook(
            $this->webhookRequest($this->startMessage((int) self::CHAT_ID), self::SECRET)
        );

        $this->assertSame(403, $res->getStatusCode(), 'An unset secret must not authenticate anyone.');
    }

    public function testEmptySecretHeaderIsRejected(): void
    {
        $res = $this->configuredPlugin()->handleWebhook(
            $this->webhookRequest($this->startMessage((int) self::CHAT_ID), '')
        );

        $this->assertSame(403, $res->getStatusCode());
    }
}
