<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DockerDevEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private function compose(): array
    {
        /** @var array<string, mixed> $compose */
        $compose = (array) yaml_parse_file($this->root . '/docker-compose.yml');

        return $compose;
    }

    public function testDockerfileUsesPhp83ApacheBaseImage(): void
    {
        $dockerfile = (string) file_get_contents($this->root . '/Dockerfile');
        $this->assertStringContainsString('FROM php:8.3-apache', $dockerfile);
    }

    public function testDockerfileSetsApacheDocumentRootToPublic(): void
    {
        $dockerfile = (string) file_get_contents($this->root . '/Dockerfile');
        $this->assertStringContainsString('APACHE_DOCUMENT_ROOT=/var/www/html/public', $dockerfile);
    }

    public function testDockerfileInstallsRequiredPhpExtensions(): void
    {
        $dockerfile = (string) file_get_contents($this->root . '/Dockerfile');
        foreach (['bcmath', 'gd', 'pdo_mysql', 'zip'] as $ext) {
            $this->assertStringContainsString($ext, $dockerfile, "Missing extension: {$ext}");
        }
        $this->assertStringContainsString('pecl install redis', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-enable redis', $dockerfile);
    }

    public function testDockerfileIncludesComposer2(): void
    {
        $dockerfile = (string) file_get_contents($this->root . '/Dockerfile');
        $this->assertStringContainsString('composer:2', $dockerfile);
        $this->assertStringContainsString('/usr/bin/composer', $dockerfile);
    }

    public function testComposeAppPortIsLoopbackBoundOnly(): void
    {
        $compose = $this->compose();
        /** @var array<string, mixed> $services */
        $services = (array) ($compose['services'] ?? []);
        /** @var array<string, mixed> $app */
        $app = (array) ($services['app'] ?? []);
        /** @var list<string> $ports */
        $ports = (array) ($app['ports'] ?? []);

        $this->assertSame(['127.0.0.1:8080:80'], $ports);
    }

    public function testComposeDatabaseAndCachePortsAreNotPublished(): void
    {
        $compose = $this->compose();
        /** @var array<string, mixed> $services */
        $services = (array) ($compose['services'] ?? []);
        /** @var array<string, mixed> $db */
        $db = (array) ($services['db'] ?? []);
        /** @var array<string, mixed> $cache */
        $cache = (array) ($services['cache'] ?? []);

        $this->assertArrayNotHasKey('ports', $db);
        $this->assertArrayNotHasKey('ports', $cache);
    }

    public function testComposeAppWaitsForHealthyDatabaseAndCache(): void
    {
        $compose = $this->compose();
        /** @var array<string, mixed> $services */
        $services = (array) ($compose['services'] ?? []);
        /** @var array<string, mixed> $app */
        $app = (array) ($services['app'] ?? []);
        /** @var array<string, mixed> $dependsOn */
        $dependsOn = (array) ($app['depends_on'] ?? []);
        /** @var array<string, mixed> $dbDep */
        $dbDep = (array) ($dependsOn['db'] ?? []);
        /** @var array<string, mixed> $cacheDep */
        $cacheDep = (array) ($dependsOn['cache'] ?? []);

        $this->assertSame('service_healthy', $dbDep['condition'] ?? null);
        $this->assertSame('service_healthy', $cacheDep['condition'] ?? null);

        /** @var array<string, mixed> $db */
        $db = (array) ($services['db'] ?? []);
        /** @var array<string, mixed> $cache */
        $cache = (array) ($services['cache'] ?? []);
        $this->assertArrayHasKey('healthcheck', $db);
        $this->assertArrayHasKey('healthcheck', $cache);
    }

    public function testComposeBindsSourceAndUsesNamedDatabaseVolume(): void
    {
        $compose = $this->compose();
        /** @var array<string, mixed> $services */
        $services = (array) ($compose['services'] ?? []);
        /** @var array<string, mixed> $app */
        $app = (array) ($services['app'] ?? []);
        /** @var array<string, mixed> $db */
        $db = (array) ($services['db'] ?? []);
        /** @var list<string> $appVolumes */
        $appVolumes = (array) ($app['volumes'] ?? []);
        /** @var list<string> $dbVolumes */
        $dbVolumes = (array) ($db['volumes'] ?? []);
        /** @var array<string, mixed> $topVolumes */
        $topVolumes = (array) ($compose['volumes'] ?? []);

        $this->assertContains('.:/var/www/html', $appVolumes);
        $this->assertContains('db_data:/var/lib/mysql', $dbVolumes);
        $this->assertArrayHasKey('db_data', $topVolumes);
    }

    public function testDockerfileEntrypointChownsWritableDirectoriesForRuntimeUser(): void
    {
        $dockerfile = (string) file_get_contents($this->root . '/Dockerfile');
        $this->assertStringContainsString('chown -R www-data:www-data "$APP_DIR/storage"', $dockerfile);
        $this->assertStringContainsString('chown -R www-data:www-data "$APP_DIR/public/assets/uploads"', $dockerfile);
        $this->assertStringContainsString('setpriv --reuid=www-data --regid=www-data', $dockerfile);
    }

    public function testMakefileDevInstallsComposerDependencies(): void
    {
        $makefile = (string) file_get_contents($this->root . '/Makefile');
        $this->assertStringContainsString('composer install', $makefile);
        $this->assertStringContainsString('docker compose up -d --build --wait', $makefile);
    }

    public function testMakefileDevResetRemovesVolumesWhileDevDownPreservesThem(): void
    {
        $makefile = (string) file_get_contents($this->root . '/Makefile');
        $this->assertMatchesRegularExpression('/^dev-reset:\s*\n\s*docker compose down -v/m', $makefile);
        $this->assertMatchesRegularExpression('/^dev-down:\s*\n\s*docker compose down\s*$/m', $makefile);
    }

    public function testDocumentationDescribesInstallerWorkflowAndDatabaseHost(): void
    {
        $docs = (string) file_get_contents($this->root . '/docs/LOCAL_SETUP.md');
        $this->assertStringContainsString('/install', $docs);
        $this->assertStringContainsString('host **`db`**', $docs);
        $this->assertStringContainsString('make dev', $docs);
    }

    public function testDockerignoreExcludesSecretsAndDependencies(): void
    {
        $ignore = (string) file_get_contents($this->root . '/.dockerignore');
        $this->assertStringContainsString('.env', $ignore);
        $this->assertStringContainsString('vendor', $ignore);
        $this->assertStringContainsString('node_modules', $ignore);
    }
}
