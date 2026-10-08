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
 * The inbound webhook fails closed the moment a secret exists. A secret is only
 * useful once Telegram has been told to send it, so boot() must persist one for
 * a legacy install AND remember whether Telegram has accepted it - otherwise
 * either the endpoint runs unauthenticated, or it 403s every real update until
 * an operator re-saves the plugin settings by hand.
 *
 * These tests are hermetic. DomainUrlService is bound to a value that is not a
 * DomainUrlService, so registerWebhookWithTelegram() bails on its type guard
 * before curl_exec() and no outbound HTTPS request is ever made. That is the
 * "service unavailable" branch, which behaves identically to an unreachable
 * Telegram from the caller's point of view - the state to assert against: the
 * secret must still be persisted, the registration marker must stay unset so a
 * later boot retries, and the retry must back off rather than hammer the API.
 *
 * @see https://github.com/own-pay/OwnPay/issues/616
 */
final class TelegramWebhookSecretProvisioningTest extends TestCase
{
    private const CHAT_ID = '987654321';
    private const TOKEN   = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';

    /**
     * @var array<int, array{key: string, value: string}>
     */
    private array $writes = [];

    /**
     * Boots a plugin against a settings group seeded with $rows.
     *
     * @param array<string, string> $rows
     */
    private function bootWith(array $rows): Plugin
    {
        $this->writes = [];

        $db = $this->createMock(Database::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn(string $k, string $v): array => ['key_name' => $k, 'value' => $v],
            array_keys($rows),
            array_values($rows)
        ));
        $db->method('exists')->willReturn(true);
        $db->method('update')->willReturnCallback(
            function (string $sql, array $params): int {
                // SettingsRepository::set() issues
                // UPDATE op_settings SET value = :v ... WHERE group_name = :g AND key_name = :k
                if (isset($params['g'], $params['k'], $params['v'])) {
                    $this->writes[] = ['key' => (string) $params['k'], 'value' => (string) $params['v']];
                }
                return 1;
            }
        );

        $container = new Container();
        $container->instance(Database::class, $db);
        $container->instance(SettingsRepository::class, new SettingsRepository($db));
        // Not a DomainUrlService, so the registration attempt short-circuits on
        // its type guard instead of making a real call to api.telegram.org.
        $container->instance(\OwnPay\Service\Domain\DomainUrlService::class, new \stdClass());

        $plugin = new Plugin();
        $plugin->boot($container);

        return $plugin;
    }

    /**
     * @return array<string, string> Keyed view of what the plugin persisted.
     */
    private function writtenSettings(): array
    {
        $out = [];
        foreach ($this->writes as $write) {
            $out[$write['key']] = $write['value'];
        }

        return $out;
    }

    public function testLegacyInstallGetsASecretOnFirstBoot(): void
    {
        $plugin = $this->bootWith([
            'bot_token' => self::TOKEN,
            'chat_id'   => self::CHAT_ID,
        ]);

        $written = $this->writtenSettings();

        $this->assertArrayHasKey('webhook_secret', $written, 'A legacy install must be given a secret on first boot.');
        $secret = $written['webhook_secret'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,256}$/', $secret, 'Telegram only allows A-Z a-z 0-9 _ and -, length 1-256.');
    }

    public function testGeneratedSecretImmediatelyAuthenticatesInboundRequests(): void
    {
        $plugin = $this->bootWith([
            'bot_token' => self::TOKEN,
            'chat_id'   => self::CHAT_ID,
        ]);
        $secret = $this->writtenSettings()['webhook_secret'];

        $accepted = $this->post($plugin, [
            'message' => ['chat' => ['id' => (int) self::CHAT_ID], 'text' => '/noop'],
        ], $secret);
        $this->assertSame(200, $accepted->getStatusCode());

        $forged = $this->post($plugin, [
            'message' => ['chat' => ['id' => (int) self::CHAT_ID], 'text' => '/noop'],
        ], 'wrong');
        $this->assertSame(403, $forged->getStatusCode());
    }

    public function testRegistrationMarkerStaysUnsetWhileTelegramIsUnreachable(): void
    {
        $this->bootWith([
            'bot_token' => self::TOKEN,
            'chat_id'   => self::CHAT_ID,
        ]);

        $written = $this->writtenSettings();

        $this->assertArrayHasKey('webhook_secret', $written);
        $this->assertArrayNotHasKey(
            'webhook_secret_registered',
            $written,
            'A failed setWebhook must not be recorded as done, or the retry never happens.'
        );
    }

    public function testAlreadyRegisteredSecretIsNotRewritten(): void
    {
        $this->bootWith([
            'bot_token'                => self::TOKEN,
            'chat_id'                  => self::CHAT_ID,
            'webhook_secret'           => 'abc123',
            'webhook_secret_registered' => 'abc123',
        ]);

        $this->assertSame(
            [],
            $this->writes,
            'Once Telegram has confirmed the secret, boot() must not write or re-register anything.'
        );
    }

    public function testOperatorSuppliedSecretIsHonouredNotRegenerated(): void
    {
        $plugin = $this->bootWith([
            'bot_token'                 => self::TOKEN,
            'chat_id'                   => self::CHAT_ID,
            'webhook_secret'            => 'rotated-secret',
            'webhook_secret_registered' => 'previous-secret',
        ]);

        $written = $this->writtenSettings();
        $this->assertArrayNotHasKey(
            'webhook_secret',
            $written,
            'A secret the operator pasted must be kept, not silently replaced.'
        );

        $accepted = $this->post($plugin, [
            'message' => ['chat' => ['id' => (int) self::CHAT_ID], 'text' => '/noop'],
        ], 'rotated-secret');
        $this->assertSame(200, $accepted->getStatusCode());

        $stale = $this->post($plugin, [
            'message' => ['chat' => ['id' => (int) self::CHAT_ID], 'text' => '/noop'],
        ], 'previous-secret');
        $this->assertSame(403, $stale->getStatusCode(), 'The superseded secret must stop working immediately.');
    }

    public function testFailedRegistrationBacksOffInsteadOfRetryingEveryRequest(): void
    {
        $this->bootWith([
            'bot_token'                  => self::TOKEN,
            'chat_id'                    => self::CHAT_ID,
            'webhook_secret'             => 'abc123',
            'webhook_secret_registered'  => 'previous',
            // A registration attempt happened moments ago and failed.
            'webhook_secret_attempted_at' => (string) (time() - 60),
        ]);

        $this->assertSame(
            [],
            $this->writtenSettings(),
            'A recent failed attempt must not be retried on every request.'
        );
    }

    public function testBackoffExpiresSoRegistrationCanEventuallySucceed(): void
    {
        $this->bootWith([
            'bot_token'                   => self::TOKEN,
            'chat_id'                     => self::CHAT_ID,
            'webhook_secret'              => 'abc123',
            'webhook_secret_registered'   => 'previous',
            'webhook_secret_attempted_at' => (string) (time() - 7200),
        ]);

        $written = $this->writtenSettings();

        $this->assertArrayHasKey(
            'webhook_secret_attempted_at',
            $written,
            'Once the backoff window has passed, the attempt must be retried.'
        );
        $this->assertArrayNotHasKey('webhook_secret_registered', $written);
    }

    public function testUnconfiguredInstallWritesNothing(): void
    {
        $this->bootWith([]);

        $this->assertSame([], $this->writes, 'An install with no bot token must not be touched.');
    }

    private function post(Plugin $plugin, array $payload, ?string $secretHeader): \OwnPay\Http\Response
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/plugins/telegram-bot/webhook',
        ];
        if ($secretHeader !== null) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secretHeader;
        }

        $req = new Request([], [], $server, [], [], (string) json_encode($payload));

        return $plugin->handleWebhook($req);
    }
}
