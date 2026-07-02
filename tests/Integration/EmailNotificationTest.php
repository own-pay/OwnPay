<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\PluginManifest;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\CommLogRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\Communication\EmailNotificationService;
use OwnPay\Service\Communication\MailProviderInterface;

final class CapturingMailProvider implements PluginInterface, MailProviderInterface
{
    public array $sent = [];

    public static function metadata(): array
    {
        return [
            'name'        => 'Capturing Mail',
            'slug'        => 'capturing-mail',
            'version'     => '1.0.0',
            'description' => 'Test mail capture provider',
            'author'      => 'tests',
            'type'        => 'addon',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::COMMUNICATION];
    }

    public function register(EventManager $events, Container $container): void
    {
    }

    public function boot(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function uninstall(Container $container): void
    {
    }

    public function fields(): array
    {
        return [];
    }

    public function slug(): string
    {
        return 'capturing-mail';
    }

    public function send(array $message): array
    {
        $this->sent[] = $message;
        return ['success' => true, 'message_id' => 'test-' . count($this->sent)];
    }
}

final class EmailNotificationTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private CapturingMailProvider $mail;
    private EmailNotificationService $service;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->container = new Container();

        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        $this->mail = new CapturingMailProvider();
        $manifest = PluginManifest::fromArray([
            'name'         => 'Capturing Mail',
            'slug'         => 'capturing-mail',
            'version'      => '1.0.0',
            'type'         => 'addon',
            'capabilities' => ['addon', 'communication'],
        ], dirname(__DIR__, 2));

        $registry = $this->container->get(PluginRegistry::class);
        $this->assertInstanceOf(PluginRegistry::class, $registry);
        $registry->registerLoaded('capturing-mail', $this->mail, $manifest);

        $service = $this->container->get(EmailNotificationService::class);
        $this->assertInstanceOf(EmailNotificationService::class, $service);
        $this->service = $service;

        $this->resetState();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->resetState();
        }
        parent::tearDown();
    }

    private function resetState(): void
    {
        $this->db->execute("DELETE FROM op_comm_log WHERE merchant_id = 1");
        $settings = $this->container->get(SettingsRepository::class);
        if ($settings instanceof SettingsRepository) {
            $settings->deleteGroupScoped('general', 1);
            $settings->flushCache();
        }
    }

    /**
     * @param array<string, string> $keyValues
     */
    private function configure(array $keyValues): void
    {
        $settings = $this->container->get(SettingsRepository::class);
        $this->assertInstanceOf(SettingsRepository::class, $settings);
        $settings->bulkSetScoped('general', $keyValues, 1);
        $settings->flushCache();
    }

    private function sampleTransaction(): array
    {
        return [
            'merchant_id' => 1,
            'trx_id'      => 'TXN-EMAIL-1',
            'amount'      => '500.00',
            'currency'    => 'BDT',
            'gateway_slug' => 'bkash',
            'created_at'  => '2026-06-20 10:00:00',
            'metadata'    => '{}',
        ];
    }

    public function testPaymentEmailSentWithBrandSenderWhenEnabled(): void
    {
        $this->configure([
            'email_on_payment'         => '1',
            'admin_notification_email' => 'admin@brand.test',
            'mail_from_email'          => 'no-reply@brand.test',
            'mail_from_name'           => 'Brand Co',
        ]);

        $this->service->onTransactionCompleted($this->sampleTransaction());

        $this->assertCount(1, $this->mail->sent);
        $message = $this->mail->sent[0];
        $this->assertSame('admin@brand.test', $message['to']);
        $this->assertSame('Brand Co <no-reply@brand.test>', $message['from']);
        $this->assertStringContainsString('500.00', (string) $message['subject']);
        $this->assertStringContainsString('TXN-EMAIL-1', (string) ($message['html'] ?? ''));

        $logs = $this->container->get(CommLogRepository::class);
        $this->assertInstanceOf(CommLogRepository::class, $logs);
        $rows = $logs->listEmailQueue(1, 10);
        $this->assertCount(1, $rows);
        $this->assertSame('admin@brand.test', $rows[0]['recipient']);
        $this->assertSame('sent', $rows[0]['status']);
    }

    public function testPaymentEmailNotSentWhenToggleDisabled(): void
    {
        $this->configure([
            'email_on_payment'         => '0',
            'admin_notification_email' => 'admin@brand.test',
            'mail_from_email'          => 'no-reply@brand.test',
        ]);

        $this->service->onTransactionCompleted($this->sampleTransaction());

        $this->assertCount(0, $this->mail->sent);
        $logs = $this->container->get(CommLogRepository::class);
        $this->assertInstanceOf(CommLogRepository::class, $logs);
        $this->assertCount(0, $logs->listEmailQueue(1, 10));
    }

    public function testPaymentEmailNotSentWhenNoRecipientConfigured(): void
    {
        $this->configure([
            'email_on_payment'         => '1',
            'admin_notification_email' => '',
            'mail_from_email'          => 'no-reply@brand.test',
        ]);

        $this->service->onTransactionCompleted($this->sampleTransaction());

        $this->assertCount(0, $this->mail->sent);
    }

    public function testRefundEmailSentWithBareSenderWhenEnabled(): void
    {
        $this->configure([
            'email_on_refund'          => '1',
            'admin_notification_email' => 'admin@brand.test',
            'mail_from_email'          => 'no-reply@brand.test',
        ]);

        $this->service->onRefundCreated([
            'merchant_id'    => 1,
            'id'             => 99,
            'transaction_id' => 5,
            'amount'         => '120.00',
            'reason'         => 'Customer request',
            'status'         => 'completed',
            'created_at'     => '2026-06-20 11:00:00',
        ]);

        $this->assertCount(1, $this->mail->sent);
        $message = $this->mail->sent[0];
        $this->assertSame('admin@brand.test', $message['to']);
        $this->assertSame('no-reply@brand.test', $message['from']);
        $this->assertStringContainsString('120.00', (string) ($message['html'] ?? ''));
    }

    public function testListenersWiredToEventManager(): void
    {
        $this->configure([
            'email_on_payment'         => '1',
            'admin_notification_email' => 'admin@brand.test',
            'mail_from_email'          => 'no-reply@brand.test',
        ]);

        $events = $this->container->get(EventManager::class);
        $this->assertInstanceOf(EventManager::class, $events);

        $events->doAction('system.boot');
        if (!$events->hasAction('refund.created')) {
            $this->markTestSkipped('Boot-time listener wiring inactive (storage/.installed missing).');
        }

        $events->doAction('payment.transaction.completed', $this->sampleTransaction());
        $this->assertCount(1, $this->mail->sent);
    }

    public function testHandlersNeverThrowOnMalformedPayload(): void
    {
        $this->service->onTransactionCompleted([]);
        $this->service->onRefundCreated([]);
        $this->service->onTransactionCompleted(['merchant_id' => 'not-an-int']);

        $this->assertCount(0, $this->mail->sent);
    }
}
