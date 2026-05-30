<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Service\System\TranslationService;
use OwnPay\Middleware\LanguageMiddleware;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;

/**
 * LanguageSystemTest — Verifies dynamic i18n features, database-backed translation logic,
 * middleware resolution, custom locale preferences, and JSON flattener functionality.
 *
 * @group Integration
 */
final class LanguageSystemTest extends IntegrationTestCase
{
    private Database $db;
    private TranslationService $translationService;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->translationService = new TranslationService($this->db);
        $this->translationService->clearCache();

        $this->container = new Container();
        $this->container->instance(Database::class, $this->db);
        $this->container->instance(TranslationService::class, $this->translationService);
    }

    protected function tearDown(): void
    {
        // Delete any test languages created
        $this->db->execute("DELETE FROM op_languages WHERE code IN ('bn', 'fr', 'testlocale')");
        // Clear cached translations
        $this->translationService->clearCache();
        parent::tearDown();
    }

    /**
     * Test TranslationService CRUD capabilities, defaults, and translations logic.
     */
    public function testTranslationServiceLogic(): void
    {
        // 1. Verify default system language is en initially
        $defaultLang = $this->translationService->getDefaultLanguage();
        $this->assertSame('en', $defaultLang);

        // 2. Verify base translations exist and function
        $trans = $this->translationService->trans('menu.dashboard');
        $this->assertSame('Dashboard', $trans);

        // 3. Verify placeholder replacements function (Laravel-style :key)
        // Set active locale to en explicitly
        $this->translationService->setLocale('en');
        // Register key manually via upload/save or check
        $this->translationService->saveTranslations('en', array_merge(
            $this->translationService->getTranslations('en'),
            ['test.welcome' => 'Welcome :name to OwnPay!']
        ));
        $replaced = $this->translationService->trans('test.welcome', ['name' => 'John']);
        $this->assertSame('Welcome John to OwnPay!', $replaced);

        // 4. Verify fallback translation to English key if not translated in active locale
        $this->translationService->createLanguage('bn', 'Bengali');
        $this->translationService->setLocale('bn');
        
        // 'menu.payments' should resolve to English value 'Payments' since 'bn' copies 'en' first,
        // but let's clear 'bn' translations to verify fallback
        $this->translationService->saveTranslations('bn', []);
        $this->assertSame('Payments', $this->translationService->trans('menu.payments'));

        // Translate key for 'bn' and verify it translates correctly
        $this->translationService->saveTranslations('bn', ['menu.dashboard' => 'ড্যাশবোর্ড']);
        $this->assertSame('ড্যাশবোর্ড', $this->translationService->trans('menu.dashboard'));
    }

    /**
     * Test nested JSON flattening on language upload.
     */
    public function testJsonFlatteningOnUpload(): void
    {
        $nestedData = [
            'common' => [
                'buttons' => [
                    'save' => 'Save Changes Now',
                    'delete' => 'Remove Forever'
                ]
            ],
            'simple_key' => 'Simple Value'
        ];

        $this->translationService->uploadLanguage('fr', 'French', $nestedData);

        // Verify keys were flattened to dot notation in database
        $translations = $this->translationService->getTranslations('fr');
        $this->assertArrayHasKey('common.buttons.save', $translations);
        $this->assertSame('Save Changes Now', $translations['common.buttons.save']);
        $this->assertArrayHasKey('common.buttons.delete', $translations);
        $this->assertSame('Remove Forever', $translations['common.buttons.delete']);
        $this->assertArrayHasKey('simple_key', $translations);
        $this->assertSame('Simple Value', $translations['simple_key']);
    }

    /**
     * Test LanguageMiddleware resolution order:
     * 1. Falls back to global default language.
     * 2. Uses staff preference if logged in.
     */
    public function testLanguageMiddlewareLocaleResolution(): void
    {
        // Clear caches
        $this->translationService->clearCache();

        // 1. Create a dummy middleware instance
        $middleware = new LanguageMiddleware($this->container);

        // Instantiated Request directly (final class cannot be mocked)
        $request = new Request();

        // First pass: no session, should resolve to default language
        $this->translationService->setDefaultLanguage('en');
        
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response();
        };

        $middleware->handle($request, $next);
        $this->assertTrue($called);
        $this->assertSame('en', $this->translationService->getLocale());

        // 2. Set default language to 'fr' and verify middleware picks it up
        $this->translationService->createLanguage('fr', 'French');
        $this->translationService->setDefaultLanguage('fr');

        $middleware->handle($request, $next);
        $this->assertSame('fr', $this->translationService->getLocale());

        // 3. Log in a staff user and set their preference to 'en'
        $adminSession = new AdminSession();
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        // Find an active user in test database
        $user = $this->db->fetchOne("SELECT id FROM op_merchant_users WHERE status = 'active' LIMIT 1");
        if ($user) {
            $userIdVal = $user['id'] ?? null;
            $userId = is_numeric($userIdVal) ? (int)$userIdVal : 0;
            $_SESSION['auth_user_id'] = $userId;
            $this->container->instance(AdminSession::class, $adminSession);

            // Set user preference to 'en' in DB
            $this->db->execute("UPDATE op_merchant_users SET language = 'en' WHERE id = :id", ['id' => $userId]);

            // Handle request again
            $middleware->handle($request, $next);
            // Staff preference 'en' should override global default 'fr'
            $this->assertSame('en', $this->translationService->getLocale());

            // Reset user preference to null
            $this->db->execute("UPDATE op_merchant_users SET language = NULL WHERE id = :id", ['id' => $userId]);
            
            unset($_SESSION['auth_user_id']);
        }
    }

    /**
     * Test automatic recovery of language JSON files if they are missing in storage.
     */
    public function testAutomaticLanguageFileRecovery(): void
    {
        $rootDir = dirname(__DIR__, 2);
        $languagesDir = $rootDir . '/storage/languages';
        $enFile = $languagesDir . '/en.json';

        // 1. Force clear cache and delete storage en.json if it exists
        $this->translationService->clearCache();
        if (file_exists($enFile)) {
            @unlink($enFile);
        }

        $this->assertFileDoesNotExist($enFile);

        // 2. Perform a translation to trigger on-the-fly recovery of en.json
        $trans = $this->translationService->trans('menu.dashboard');
        $this->assertSame('Dashboard', $trans);

        // 3. Verify that en.json was instantly and automatically copied back
        $this->assertFileExists($enFile);

        // 4. Test recovery of custom language from database
        $this->translationService->createLanguage('testlocale', 'Test Locale');
        $testLocaleFile = $languagesDir . '/testlocale.json';
        $this->assertFileExists($testLocaleFile);

        // Delete testlocale.json from storage
        @unlink($testLocaleFile);
        $this->assertFileDoesNotExist($testLocaleFile);

        // Clear in-memory caches and switch active locale
        $this->translationService->clearCache();
        $this->translationService->setLocale('testlocale');

        // Translate - should automatically recover testlocale.json from database
        $transCustom = $this->translationService->trans('menu.dashboard');
        $this->assertSame('Dashboard', $transCustom); // Since created language copies 'en' keys

        // Verify file is automatically restored
        $this->assertFileExists($testLocaleFile);
    }
}

