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
        $this->db->execute("DELETE FROM op_languages WHERE code IN ('bn', 'fr', 'testlocale')");
        $this->translationService->clearCache();
        parent::tearDown();
    }

    public function testTranslationServiceLogic(): void
    {
        $defaultLang = $this->translationService->getDefaultLanguage();
        $this->assertSame('en', $defaultLang);

        $trans = $this->translationService->trans('menu.dashboard');
        $this->assertSame('Dashboard', $trans);

        $this->translationService->setLocale('en');
        $this->translationService->saveTranslations('en', array_merge(
            $this->translationService->getTranslations('en'),
            ['test.welcome' => 'Welcome :name to OwnPay!']
        ));
        $replaced = $this->translationService->trans('test.welcome', ['name' => 'John']);
        $this->assertSame('Welcome John to OwnPay!', $replaced);

        $this->translationService->createLanguage('bn', 'Bengali');
        $this->translationService->setLocale('bn');

        $this->translationService->saveTranslations('bn', []);
        $this->assertSame('Payments', $this->translationService->trans('menu.payments'));

        $this->translationService->saveTranslations('bn', ['menu.dashboard' => 'ড্যাশবোর্ড']);
        $this->assertSame('ড্যাশবোর্ড', $this->translationService->trans('menu.dashboard'));
    }

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

        $translations = $this->translationService->getTranslations('fr');
        $this->assertArrayHasKey('common.buttons.save', $translations);
        $this->assertSame('Save Changes Now', $translations['common.buttons.save']);
        $this->assertArrayHasKey('common.buttons.delete', $translations);
        $this->assertSame('Remove Forever', $translations['common.buttons.delete']);
        $this->assertArrayHasKey('simple_key', $translations);
        $this->assertSame('Simple Value', $translations['simple_key']);
    }

    public function testLanguageMiddlewareLocaleResolution(): void
    {
        $this->translationService->clearCache();

        $middleware = new LanguageMiddleware($this->container);
        $request = new Request();

        $this->translationService->setDefaultLanguage('en');

        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response();
        };

        $middleware->handle($request, $next);
        $this->assertTrue($called);
        $this->assertSame('en', $this->translationService->getLocale());

        $this->translationService->createLanguage('fr', 'French');
        $this->translationService->setDefaultLanguage('fr');

        $middleware->handle($request, $next);
        $this->assertSame('fr', $this->translationService->getLocale());

        $adminSession = new AdminSession();
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        $user = $this->db->fetchOne("SELECT id FROM op_merchant_users WHERE status = 'active' LIMIT 1");
        if ($user) {
            $userIdVal = $user['id'] ?? null;
            $userId = is_numeric($userIdVal) ? (int)$userIdVal : 0;
            $_SESSION['auth_user_id'] = $userId;
            $this->container->instance(AdminSession::class, $adminSession);

            $this->db->execute("UPDATE op_merchant_users SET language = 'en' WHERE id = :id", ['id' => $userId]);

            $middleware->handle($request, $next);
            $this->assertSame('en', $this->translationService->getLocale());

            $this->db->execute("UPDATE op_merchant_users SET language = NULL WHERE id = :id", ['id' => $userId]);

            unset($_SESSION['auth_user_id']);
        }
    }

    /**
     * Retries briefly to absorb a transient Windows file lock left by a prior test's handle.
     */
    private function removeFile(string $path): void
    {
        for ($i = 0; $i < 10 && file_exists($path); $i++) {
            @unlink($path);
            clearstatcache(true, $path);
            if (!file_exists($path)) {
                break;
            }
            usleep(20000);
        }
    }

    public function testAutomaticLanguageFileRecovery(): void
    {
        $rootDir = dirname(__DIR__, 2);
        $languagesDir = $rootDir . '/storage/languages';
        $enFile = $languagesDir . '/en.json';

        $this->translationService->clearCache();
        $this->removeFile($enFile);

        $this->assertFileDoesNotExist($enFile);

        $trans = $this->translationService->trans('menu.dashboard');
        $this->assertSame('Dashboard', $trans);

        $this->assertFileExists($enFile);

        $this->translationService->createLanguage('testlocale', 'Test Locale');
        $testLocaleFile = $languagesDir . '/testlocale.json';
        $this->assertFileExists($testLocaleFile);

        $this->removeFile($testLocaleFile);
        $this->assertFileDoesNotExist($testLocaleFile);

        $this->translationService->clearCache();
        $this->translationService->setLocale('testlocale');

        $transCustom = $this->translationService->trans('menu.dashboard');
        $this->assertSame('Dashboard', $transCustom);

        $this->assertFileExists($testLocaleFile);
    }
}
