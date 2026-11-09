<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\ManualGatewayRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\PaymentIntentRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Brand\BrandThemeService;
use OwnPay\Service\Payment\CurrencyService;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\View\Theme\ActiveTheme;
use OwnPay\View\Theme\ActiveThemeResolver;
use OwnPay\View\Theme\ThemeRendererInterface;
use OwnPay\View\Theme\ThemeRendererRegistry;
use OwnPay\Controller\Checkout\PaymentIntentCheckoutController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use ReflectionMethod;
use ReflectionProperty;

final class BrandManualPaymentAutoRedirectTest extends TestCase
{
    public function testDefaultBrandThemeProvidesManualPaymentAutoRedirectZero(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturn([
            'name' => 'Demo Brand',
            'slug' => 'demo-brand',
            'logo_path' => '',
            'settings' => json_encode(['some_other_key' => '1']),
        ]);
        $db->method('fetchAll')->willReturn([]);

        $settings = new SettingsRepository($db);
        $svc = new BrandThemeService($db, $settings);
        $theme = $svc->getBrandTheme(42);

        $this->assertArrayHasKey('manual_payment_auto_redirect', $theme);
        $this->assertSame('0', $theme['manual_payment_auto_redirect']);
    }

    public function testBrandThemeRespectsMerchantManualPaymentAutoRedirectSetting(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturn([
            'name' => 'Demo Brand',
            'slug' => 'demo-brand',
            'logo_path' => '',
            'settings' => json_encode(['manual_payment_auto_redirect' => '1']),
        ]);
        $db->method('fetchAll')->willReturn([]);

        $settings = new SettingsRepository($db);
        $svc = new BrandThemeService($db, $settings);
        $theme = $svc->getBrandTheme(42);

        $this->assertSame('1', $theme['manual_payment_auto_redirect']);
    }

    public function testBrandThemeFallbackWhenMerchantNotFound(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturn(null);

        $settings = new SettingsRepository($db);
        $svc = new BrandThemeService($db, $settings);
        $theme = $svc->getBrandTheme(999);

        $this->assertArrayHasKey('manual_payment_auto_redirect', $theme);
        $this->assertSame('0', $theme['manual_payment_auto_redirect']);
    }

    public function testRenderStatusSuppressesRedirectForManualPendingWhenDisabled(): void
    {
        [$controller, $renderedContextRef] = $this->createControllerWithCapturedRender([
            'name' => 'Test Merchant',
            'manual_payment_auto_redirect' => '0',
        ]);

        $intent = [
            'id' => 10,
            'merchant_id' => 5,
            'uuid' => 'pi_uuid_123',
            'token' => 'tok_abc123',
            'amount' => '500.00',
            'currency' => 'BDT',
            'status' => 'processing',
            'redirect_url' => 'https://merchant.test/wc-api/return',
        ];

        $txn = [
            'id' => 100,
            'status' => 'awaiting_verification',
            'currency' => 'BDT',
            'trx_id' => 'OP-TXN-123',
            'amount' => '500.00',
        ];

        $this->invokeRenderStatus($controller, 'tok_abc123', 'processing', $intent, $txn);

        $captured = $renderedContextRef();
        $this->assertNotNull($captured);
        $this->assertTrue($captured['is_manual_pending']);
        $this->assertSame('', $captured['merchant_redirect_url'], 'Redirect URL should be empty string to suppress countdown');
        // When redirect is disabled the original processing status is preserved;
        // the template override (is_manual_pending) drives the review UI instead.
        $this->assertSame('processing', $captured['intent_status']);
    }

    public function testRenderStatusAllowsRedirectAndSetsPendingStatusWhenEnabled(): void
    {
        [$controller, $renderedContextRef] = $this->createControllerWithCapturedRender([
            'name' => 'Test Merchant',
            'manual_payment_auto_redirect' => '1',
        ]);

        $intent = [
            'id' => 10,
            'merchant_id' => 5,
            'uuid' => 'pi_uuid_123',
            'token' => 'tok_abc123',
            'amount' => '500.00',
            'currency' => 'BDT',
            'status' => 'processing',
            'redirect_url' => 'https://merchant.test/wc-api/return',
        ];

        $txn = [
            'id' => 100,
            'status' => 'awaiting_verification',
            'currency' => 'BDT',
            'trx_id' => 'OP-TXN-123',
            'amount' => '500.00',
        ];

        $this->invokeRenderStatus($controller, 'tok_abc123', 'processing', $intent, $txn);

        $captured = $renderedContextRef();
        $this->assertNotNull($captured);
        $this->assertTrue($captured['is_manual_pending']);
        $this->assertSame('https://merchant.test/wc-api/return', $captured['merchant_redirect_url']);
        $this->assertSame('pending', $captured['intent_status'], 'Intent status must be forced to pending for merchant redirect');
    }

    public function testRenderStatusLeavesApiGatewayFlowUnaffected(): void
    {
        [$controller, $renderedContextRef] = $this->createControllerWithCapturedRender([
            'name' => 'Test Merchant',
            'manual_payment_auto_redirect' => '0',
        ]);

        $intent = [
            'id' => 10,
            'merchant_id' => 5,
            'uuid' => 'pi_uuid_123',
            'token' => 'tok_abc123',
            'amount' => '500.00',
            'currency' => 'BDT',
            'status' => 'processing',
            'redirect_url' => 'https://merchant.test/wc-api/return',
        ];

        $txn = [
            'id' => 100,
            'status' => 'processing', // API gateway in-flight, not manual
            'currency' => 'BDT',
            'trx_id' => 'OP-TXN-123',
            'amount' => '500.00',
        ];

        $this->invokeRenderStatus($controller, 'tok_abc123', 'processing', $intent, $txn);

        $captured = $renderedContextRef();
        $this->assertNotNull($captured);
        $this->assertFalse($captured['is_manual_pending']);
        $this->assertSame('https://merchant.test/wc-api/return', $captured['merchant_redirect_url']);
        $this->assertSame('processing', $captured['intent_status']);
    }

    public function testPendingTemplateShowsReviewBadgeWhenManualPending(): void
    {
        $html = $this->renderPending([
            'status' => 'processing',
            'txn' => ['trx_id' => 'OP-TXN-123', 'amount' => '500.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'gateway_slug' => 'bkash'],
            'is_manual_pending' => true,
        ]);
        $this->assertStringContainsString('st-badge-review', $html);
        $this->assertStringNotContainsString('st-badge-processing', $html);
        $this->assertStringContainsString('Under Review', $html);
    }

    public function testPendingTemplateShowsProcessingBadgeWithoutManualPending(): void
    {
        $html = $this->renderPending([
            'status' => 'processing',
            'txn' => ['trx_id' => 'OP-TXN-123', 'amount' => '500.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'gateway_slug' => 'stripe'],
            'is_manual_pending' => false,
        ]);
        $this->assertStringContainsString('st-badge-processing', $html);
        $this->assertStringNotContainsString('st-badge-review', $html);
        $this->assertStringContainsString('Processing', $html);
    }

    public function testPendingTemplateHandlesMissingManualPendingFlag(): void
    {
        $html = $this->renderPending([
            'status' => 'processing',
            'txn' => ['trx_id' => 'OP-TXN-123', 'amount' => '500.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'gateway_slug' => 'stripe'],
        ]);
        $this->assertStringContainsString('st-badge-processing', $html);
        $this->assertStringNotContainsString('st-badge-review', $html);
        $this->assertStringContainsString('Processing', $html);
    }

    public function testPendingTemplateShowsReviewBadgeForPendingReviewStatus(): void
    {
        $html = $this->renderPending([
            'status' => 'pending_review',
            'txn' => ['trx_id' => 'OP-TXN-123', 'amount' => '500.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'gateway_slug' => 'nagad'],
        ]);
        $this->assertStringContainsString('st-badge-review', $html);
        $this->assertStringNotContainsString('st-badge-processing', $html);
        $this->assertStringContainsString('Under Review', $html);
    }

    /**
     * @param array<string, mixed> $brandThemeData
     * @return array{0: PaymentIntentCheckoutController, 1: callable(): ?array<string, mixed>}
     */
    private function createControllerWithCapturedRender(array $brandThemeData): array
    {
        $capturedContext = null;

        $c = new Container();

        // Register brand theme in container via custom BrandThemeService
        $brandDb = $this->createMock(Database::class);
        $brandDb->method('fetchOne')->willReturn([
            'name' => $brandThemeData['name'] ?? 'Test Merchant',
            'slug' => 'test-merchant',
            'logo_path' => '',
            'settings' => json_encode($brandThemeData),
        ]);
        $brandDb->method('fetchAll')->willReturn([]);
        $brandSettings = new SettingsRepository($brandDb);
        $themeSvc = new BrandThemeService($brandDb, $brandSettings);
        $c->singleton(BrandThemeService::class, static fn () => $themeSvc);

        $mockRenderer = $this->createMock(ThemeRendererInterface::class);
        $mockRenderer->method('render')->willReturnCallback(function (string $path, array $ctx) use (&$capturedContext): string {
            $capturedContext = $ctx;
            return '<html>rendered</html>';
        });

        $registry = (new ReflectionClass(ThemeRendererRegistry::class))->newInstanceWithoutConstructor();
        $regProp = new ReflectionProperty(ThemeRendererRegistry::class, 'renderers');
        $regProp->setAccessible(true);
        $regProp->setValue($registry, ['twig' => $mockRenderer]);
        $c->singleton(ThemeRendererRegistry::class, static fn () => $registry);

        $mainDb = $this->createMock(Database::class);
        $mainDb->method('fetchAll')->willReturn([]);
        $settings = new SettingsRepository($mainDb);

        $pluginRegistry = (new ReflectionClass(\OwnPay\Plugin\PluginRegistry::class))->newInstanceWithoutConstructor();
        $themesDir = dirname(__DIR__, 2) . '/modules/themes';
        $resolver = new ActiveThemeResolver($settings, $pluginRegistry, $themesDir);
        $c->singleton(ActiveThemeResolver::class, static fn () => $resolver);

        $events = new EventManager();
        $txnRepo = (new ReflectionClass(TransactionRepository::class))->newInstanceWithoutConstructor();
        $manualGw = (new ReflectionClass(ManualGatewayRepository::class))->newInstanceWithoutConstructor();
        $apiGw = (new ReflectionClass(GatewayConfigRepository::class))->newInstanceWithoutConstructor();
        $merchants = (new ReflectionClass(MerchantRepository::class))->newInstanceWithoutConstructor();

        $intents = (new ReflectionClass(PaymentIntentRepository::class))->newInstanceWithoutConstructor();
        $paymentService = (new ReflectionClass(PaymentService::class))->newInstanceWithoutConstructor();
        $currencyService = new CurrencyService($mainDb);
        $transactionService = (new ReflectionClass(TransactionService::class))->newInstanceWithoutConstructor();

        $controller = new PaymentIntentCheckoutController(
            $c,
            $events,
            $txnRepo,
            $manualGw,
            $apiGw,
            $merchants,
            $settings,
            $intents,
            $paymentService,
            $currencyService,
            $transactionService,
            $mainDb
        );

        $capturedRef = static function () use (&$capturedContext): ?array {
            return $capturedContext;
        };

        return [$controller, $capturedRef];
    }

    /**
     * @param PaymentIntentCheckoutController $controller
     * @param string $ref
     * @param string $status
     * @param array<string, mixed>|null $intent
     * @param array<string, mixed>|null $txn
     */
    private function invokeRenderStatus(
        PaymentIntentCheckoutController $controller,
        string $ref,
        string $status,
        ?array $intent,
        ?array $txn
    ): void {
        $dbProp = new ReflectionProperty($controller, 'db');
        $dbProp->setAccessible(true);
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $dbMock */
        $dbMock = $dbProp->getValue($controller);
        $dbMock->method('fetchOne')->willReturn($txn);

        $method = new ReflectionMethod($controller, 'renderStatus');
        $method->setAccessible(true);
        $method->invoke($controller, $ref, $status, $intent);
    }

    private function renderPending(array $ctx): string
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader, ['cache' => false]);
        $twig->addFunction(new \Twig\TwigFunction('hook', fn (string $name) => ''));
        $twig->addFunction(new \Twig\TwigFunction('locale', fn (): string => 'en'));
        $twig->addFunction(new \Twig\TwigFunction('__', fn (string $key, ...$args) => $key));
        $twig->addFunction(new \Twig\TwigFunction('enqueued_assets', fn (string $type) => '', ['is_safe' => ['html']]));
        return $twig->render('checkout/partials/_pending.twig', array_merge([
            'brand' => ['name' => 'Test Merchant'],
            'lang' => ['pending_msg' => 'Your payment is under review.'],
            'status_label' => 'Payment Under Review',
            'intent_status' => 'pending',
        ], $ctx));
    }
}
