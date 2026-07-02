<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Service\System\TranslationService;

final class TranslationCodeSecurityTest extends IntegrationTestCase
{
    private TranslationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $db = Database::getInstance();
        $c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $db);

        $svc = $c->get(TranslationService::class);
        $this->assertInstanceOf(TranslationService::class, $svc);
        $this->svc = $svc;
    }

    public function testCreateLanguageRejectsTraversalCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createLanguage('../../evil', 'Evil');
    }

    public function testDeleteLanguageRejectsTraversalCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->deleteLanguage('../../../public/shell');
    }

    public function testSaveTranslationsRejectsTraversalCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->saveTranslations('..%2f..%2fx', ['k' => 'v']);
    }

    public function testSetLocaleIgnoresTraversalCode(): void
    {
        $this->svc->setLocale('en');
        $this->svc->setLocale('../../../../etc/passwd');
        $this->assertSame('en', $this->svc->getLocale(), 'an unsafe locale must be ignored');
    }

    public function testValidCustomCodeStillAccepted(): void
    {
        $this->svc->setLocale('en');
        $this->svc->setLocale('testlocale');
        $this->assertSame('testlocale', $this->svc->getLocale());
        $this->svc->setLocale('en');
    }
}
