<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Modules\Addons\SmsGateway\Plugin;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\Customer\CustomerPiiService;
use OwnPay\Repository\CommLogRepository;

final class SmsGatewayAddonTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private Plugin $plugin;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
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

        // Pre-configure plugin settings in DB (brand-scoped for merchant_id = 1)
        $settingsRepo = $this->container->get(SettingsRepository::class);
        if ($settingsRepo instanceof SettingsRepository) {
            $settingsRepo->bulkSetScoped('plugin.sms-gateway', [
                'provider'                 => 'custom',
                'custom_api_url'           => 'https://example.com/api/sms',
                'custom_api_key'           => 'secret_api_key',
                'custom_api_method'        => 'POST',
                'custom_api_body_template' => '{"to":"{{to}}","message":"{{message}}"}',
                'send_on_invoice_created'   => '1',
                'invoice_created_template' => 'Hi {{customer}}, invoice {{invoice_number}} of {{currency}} {{due_amount}} is ready. Due date: {{due_date}}. Pay here: {{url}}',
                'send_on_payment_success'   => '1',
                'payment_success_template' => 'Thank you {{customer}}, your payment of {{currency}} {{amount}} (Trx ID: {{trx_id}}) was successful!',
                'enabled'                  => '1',
            ], 1);
        }

        // Require plugin explicitly
        require_once dirname(__DIR__, 2) . '/modules/addons/sms-gateway/Plugin.php';

        // Initialize and boot plugin
        $this->plugin = new Plugin();
        $this->plugin->boot($this->container);

        // Register loaded plugin in PluginRegistry so resolved by CommunicationService
        $manifest = \OwnPay\Plugin\PluginManifest::fromArray([
            'name' => 'SMS Gateway',
            'slug' => 'sms-gateway',
            'version' => '1.0.0',
            'type' => 'addon',
            'capabilities' => ['addon', 'communication'],
        ], dirname(__DIR__, 2) . '/modules/addons/sms-gateway');

        $registry = $this->container->get(\OwnPay\Plugin\PluginRegistry::class);
        if ($registry instanceof \OwnPay\Plugin\PluginRegistry) {
            $registry->registerLoaded('sms-gateway', $this->plugin, $manifest);
        }

        // Clean up test data
        $this->db->execute("DELETE FROM op_comm_log WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_invoices WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_customers WHERE merchant_id = 1");
    }

    protected function tearDown(): void
    {
        $this->db->execute("DELETE FROM op_comm_log WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_invoices WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 1");
        $this->db->execute("DELETE FROM op_customers WHERE merchant_id = 1");

        parent::tearDown();
    }

    public function testInvoiceCreatedSMSAlertTriggered(): void
    {
        // 1. Create a customer with encrypted PII
        $piiService = $this->container->get(CustomerPiiService::class);
        $this->assertInstanceOf(CustomerPiiService::class, $piiService);
        
        $customer = $piiService->create(1, [
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '+8801700000000',
            'address' => 'Dhaka, Bangladesh',
        ]);
        $custIdVal = $customer['id'] ?? null;
        $customerId = is_numeric($custIdVal) ? (int) $custIdVal : 0;

        // 2. Trigger invoice creation simulation
        $invoice = [
            'merchant_id'    => 1,
            'invoice_number' => 'INV-2026-001',
            'customer_id'    => $customerId,
            'total'          => '1500.00',
            'currency'       => 'BDT',
            'due_date'       => '2026-06-15',
            'token'          => 'invoice_test_token_123',
        ];

        // Manually dispatch invoice.created hook
        $this->plugin->onInvoiceCreated($invoice);

        // 3. Assert SMS communication log row exists with correct rendered body
        $commLogRepo = $this->container->get(CommLogRepository::class);
        $this->assertInstanceOf(CommLogRepository::class, $commLogRepo);
        
        $logs = $commLogRepo->listSmsQueue(1, 10);
        $this->assertCount(1, $logs);
        
        $log = $logs[0];
        $this->assertSame('+8801700000000', $log['recipient']);
        $this->assertSame('sms', $log['channel']);
        
        $expectedMessage = 'Hi John Doe, invoice INV-2026-001 of BDT 1500.00 is ready. Due date: 2026-06-15. Pay here: https://localhost/invoice/invoice_test_token_123';
        $this->assertSame($expectedMessage, $log['body']);
    }

    public function testPaymentSuccessSMSAlertTriggered(): void
    {
        // 1. Create a customer with encrypted PII
        $piiService = $this->container->get(CustomerPiiService::class);
        $this->assertInstanceOf(CustomerPiiService::class, $piiService);
        
        $customer = $piiService->create(1, [
            'name'    => 'Jane Doe',
            'email'   => 'jane@example.com',
            'phone'   => '+8801800000000',
            'address' => 'Chittagong, Bangladesh',
        ]);
        $custIdVal = $customer['id'] ?? null;
        $customerId = is_numeric($custIdVal) ? (int) $custIdVal : 0;

        // 2. Trigger payment completed simulation
        $transaction = [
            'merchant_id' => 1,
            'customer_id' => $customerId,
            'amount'      => '500.00',
            'currency'    => 'BDT',
            'trx_id'      => 'TXN-SUCCESS-789',
            'invoice_id'  => null,
        ];

        // Manually dispatch payment.transaction.completed hook
        $this->plugin->onPaymentSuccess($transaction);

        // 3. Assert SMS log exists
        $commLogRepo = $this->container->get(CommLogRepository::class);
        $this->assertInstanceOf(CommLogRepository::class, $commLogRepo);
        
        $logs = $commLogRepo->listSmsQueue(1, 10);
        $this->assertCount(1, $logs);
        
        $log = $logs[0];
        $this->assertSame('+8801800000000', $log['recipient']);
        
        $expectedMessage = 'Thank you Jane Doe, your payment of BDT 500.00 (Trx ID: TXN-SUCCESS-789) was successful!';
        $this->assertSame($expectedMessage, $log['body']);
    }

    public function testSMSAlertsAreNotSentIfTogglesAreDisabled(): void
    {
        // Disable triggers in settings
        $settingsRepo = $this->container->get(SettingsRepository::class);
        if ($settingsRepo instanceof SettingsRepository) {
            $settingsRepo->bulkSetScoped('plugin.sms-gateway', [
                'send_on_invoice_created' => '0',
                'send_on_payment_success' => '0',
            ], 1);
        }

        // Create customer
        $piiService = $this->container->get(CustomerPiiService::class);
        $this->assertInstanceOf(CustomerPiiService::class, $piiService);
        
        $customer = $piiService->create(1, [
            'name'    => 'Alice Doe',
            'email'   => 'alice@example.com',
            'phone'   => '+8801900000000',
            'address' => 'Sylhet, Bangladesh',
        ]);
        $custIdVal = $customer['id'] ?? null;
        $customerId = is_numeric($custIdVal) ? (int) $custIdVal : 0;

        // Trigger events
        $invoice = [
            'merchant_id'    => 1,
            'invoice_number' => 'INV-DISABLED',
            'customer_id'    => $customerId,
            'total'          => '100.00',
            'currency'       => 'BDT',
            'due_date'       => '2026-06-30',
            'token'          => 'disabled_token',
        ];
        $this->plugin->onInvoiceCreated($invoice);

        $transaction = [
            'merchant_id' => 1,
            'customer_id' => $customerId,
            'amount'      => '100.00',
            'currency'    => 'BDT',
            'trx_id'      => 'TXN-DISABLED',
        ];
        $this->plugin->onPaymentSuccess($transaction);

        // Assert no SMS gets queued
        $commLogRepo = $this->container->get(CommLogRepository::class);
        $this->assertInstanceOf(CommLogRepository::class, $commLogRepo);
        
        $logs = $commLogRepo->listSmsQueue(1, 10);
        $this->assertCount(0, $logs);
    }
}
