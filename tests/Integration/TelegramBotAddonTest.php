<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Modules\Addons\TelegramBot\Plugin;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\Customer\CustomerPiiService;
use OwnPay\Service\Payment\PaymentLinkService;
use OwnPay\Service\Payment\InvoiceService;

final class TelegramBotAddonTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->container = new Container();

        // Load core services
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        // Pre-configure plugin settings in DB
        $settingsRepo = $this->container->get(SettingsRepository::class);
        if ($settingsRepo instanceof SettingsRepository) {
            $settingsRepo->bulkSet('plugin.telegram-bot', [
                'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
                'chat_id' => '987654321',
                'alert_on_success' => '1',
                'alert_on_failure' => '1',
            ]);
        }

        // Require plugin explicitly
        require_once dirname(__DIR__, 2) . '/modules/addons/telegram-bot/Plugin.php';

        // Initialize and boot plugin
        $this->plugin = new Plugin();
        $this->plugin->boot($this->container);

        // Clean up test data
        $this->db->execute("DELETE FROM op_disputes WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_refunds WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_customers WHERE merchant_id = 1");
    }

    protected function tearDown(): void
    {
        $this->db->execute("DELETE FROM op_disputes WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_refunds WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_customers WHERE merchant_id = 1");

        parent::tearDown();
    }

    public function testWebhookRejectsUnauthorizedChatId(): void
    {
        $payload = [
            'message' => [
                'chat' => ['id' => 111111111], // Wrong chat ID
                'text' => '/start'
            ]
        ];

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payload));

        $res = $this->plugin->handleWebhook($req);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testWebhookWelcomeStartCommand(): void
    {
        $payload = [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/start'
            ]
        ];

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payload));

        $res = $this->plugin->handleWebhook($req);
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode($res->getBody(), true);
        $this->assertTrue($body['ok'] ?? false);
    }

    public function testWebhookCommandsFinancialStatistics(): void
    {
        // 1. Insert a transaction for today
        $this->db->execute("
            INSERT INTO op_transactions (merchant_id, uuid, trx_id, amount, net_amount, currency, status, gateway_slug, created_at)
            VALUES (1, 'uuid-test-1', 'OP-TESTTODAY', 150.00, 150.00, 'BDT', 'completed', 'stripe', NOW())
        ");

        $payload = [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/today'
            ]
        ];

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payload));

        $res = $this->plugin->handleWebhook($req);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testWebhookRecentTransactionsList(): void
    {
        $this->db->execute("
            INSERT INTO op_transactions (merchant_id, uuid, trx_id, amount, net_amount, currency, status, gateway_slug, created_at)
            VALUES (1, 'uuid-test-2', 'OP-RECENT1', 200.00, 200.00, 'USD', 'completed', 'stripe', NOW())
        ");

        $payload = [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/recent'
            ]
        ];

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payload));

        $res = $this->plugin->handleWebhook($req);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testWebhookGetTransactionStatusDetails(): void
    {
        $this->db->execute("
            INSERT INTO op_transactions (merchant_id, uuid, trx_id, amount, net_amount, currency, status, gateway_slug, created_at)
            VALUES (1, 'uuid-test-3', 'OP-STATUS123', 50.00, 50.00, 'EUR', 'completed', 'stripe', NOW())
        ");

        $payload = [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/status OP-STATUS123'
            ]
        ];

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payload));

        $res = $this->plugin->handleWebhook($req);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testWebhookDisputesAndRefundsCommands(): void
    {
        // Test disputes
        $payloadDsp = [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/disputes'
            ]
        ];
        $reqDsp = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payloadDsp));

        $resDsp = $this->plugin->handleWebhook($reqDsp);
        $this->assertSame(200, $resDsp->getStatusCode());

        // Test refunds
        $payloadRef = [
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/refunds'
            ]
        ];
        $reqRef = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payloadRef));

        $resRef = $this->plugin->handleWebhook($reqRef);
        $this->assertSame(200, $resRef->getStatusCode());
    }

    public function testCallbackQueriesHandling(): void
    {
        // 1. Test cmd_today callback
        $payloadToday = [
            'callback_query' => [
                'id' => 'cb-1',
                'data' => 'cmd_today',
                'message' => [
                    'chat' => ['id' => 987654321]
                ]
            ]
        ];
        $reqToday = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payloadToday));

        $resToday = $this->plugin->handleWebhook($reqToday);
        $this->assertSame(200, $resToday->getStatusCode());

        // 2. Test txn_details callback
        $payloadDetails = [
            'callback_query' => [
                'id' => 'cb-2',
                'data' => 'txn_details:OP-STATUS123',
                'message' => [
                    'chat' => ['id' => 987654321]
                ]
            ]
        ];
        $reqDetails = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/plugins/telegram-bot/webhook'
        ], [], [], json_encode($payloadDetails));

        $resDetails = $this->plugin->handleWebhook($reqDetails);
        $this->assertSame(200, $resDetails->getStatusCode());
    }
}
