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
use OwnPay\Repository\PasswordResetRepository;
use OwnPay\Security\Authenticator;
use OwnPay\Service\Auth\PasswordResetService;
use OwnPay\Service\Communication\MailProviderInterface;

final class ResetMailCapture implements PluginInterface, MailProviderInterface
{
    public array $sent = [];

    public static function metadata(): array
    {
        return ['name' => 'Reset Mail', 'slug' => 'reset-mail', 'version' => '1.0.0', 'description' => 't', 'author' => 't', 'type' => 'addon'];
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
        return 'reset-mail';
    }

    public function send(array $message): array
    {
        $this->sent[] = $message;
        return ['success' => true, 'message_id' => 'test'];
    }
}

final class PasswordResetServiceTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private PasswordResetService $service;
    private PasswordResetRepository $tokens;
    private ResetMailCapture $mail;
    private int $userId = 0;
    private string $email = 'pwreset-tester@example.test';
    private const ROLE_SLUG = 'pwreset-test-role';

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

        $this->mail = new ResetMailCapture();
        $manifest = PluginManifest::fromArray([
            'name' => 'Reset Mail', 'slug' => 'reset-mail', 'version' => '1.0.0', 'type' => 'addon',
            'capabilities' => ['addon', 'communication'],
        ], dirname(__DIR__, 2));
        $registry = $this->container->get(PluginRegistry::class);
        $this->assertInstanceOf(PluginRegistry::class, $registry);
        $registry->registerLoaded('reset-mail', $this->mail, $manifest);

        $service = $this->container->get(PasswordResetService::class);
        $this->assertInstanceOf(PasswordResetService::class, $service);
        $this->service = $service;

        $tokens = $this->container->get(PasswordResetRepository::class);
        $this->assertInstanceOf(PasswordResetRepository::class, $tokens);
        $this->tokens = $tokens;

        $this->cleanup();

        // Throwaway role to satisfy merchant_users.role_id FK
        $this->db->execute(
            "INSERT INTO op_roles (merchant_id, name, slug) VALUES (1, 'PwReset Test Role', :slug)",
            ['slug' => self::ROLE_SLUG]
        );
        $roleRow = $this->db->fetchOne("SELECT id FROM op_roles WHERE merchant_id = 1 AND slug = :slug", ['slug' => self::ROLE_SLUG]);
        $roleId = (is_array($roleRow) && isset($roleRow['id']) && is_scalar($roleRow['id'])) ? (int) $roleRow['id'] : 0;
        $this->assertGreaterThan(0, $roleId);

        $this->db->execute(
            "INSERT INTO op_merchant_users (merchant_id, role_id, name, email, password_hash, status)
             VALUES (1, :rid, 'PwReset Tester', :email, :hash, 'active')",
            ['rid' => $roleId, 'email' => $this->email, 'hash' => Authenticator::hashPassword('OldPassw0rd!')]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_merchant_users WHERE email = :email", ['email' => $this->email]);
        $this->userId = (is_array($row) && isset($row['id']) && is_scalar($row['id'])) ? (int) $row['id'] : 0;
        $this->assertGreaterThan(0, $this->userId);
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_merchant_users WHERE email = :email", ['email' => $this->email]);
        $this->db->execute("DELETE FROM op_roles WHERE merchant_id = 1 AND slug = :slug", ['slug' => self::ROLE_SLUG]);
    }

    private function currentHash(): string
    {
        $row = $this->db->fetchOne("SELECT password_hash FROM op_merchant_users WHERE id = :id", ['id' => $this->userId]);
        return (is_array($row) && is_scalar($row['password_hash'] ?? null)) ? (string) $row['password_hash'] : '';
    }

    private function countValidTokens(): int
    {
        $v = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_password_resets WHERE user_id = :id AND used_at IS NULL AND expires_at > NOW(6)",
            ['id' => $this->userId]
        );
        return is_numeric($v) ? (int) $v : 0;
    }

    public function testRequestCreatesOneTokenAndEmailsLink(): void
    {
        $this->service->requestReset($this->email);

        $this->assertSame(1, $this->countValidTokens());
        $this->assertCount(1, $this->mail->sent);
        $message = $this->mail->sent[0];
        $this->assertSame($this->email, $message['to']);
        $this->assertStringContainsString('/reset-password?token=', (string) ($message['html'] ?? ''));
    }

    public function testUnknownEmailIssuesNothing(): void
    {
        $this->service->requestReset('nobody-here@example.test');

        $this->assertCount(0, $this->mail->sent, 'no email for unknown account (no enumeration)');
        $this->assertSame(0, $this->countValidTokens());
    }

    public function testSecondRequestInvalidatesTheFirst(): void
    {
        $this->service->requestReset($this->email);
        $this->service->requestReset($this->email);

        $this->assertSame(1, $this->countValidTokens(), 'only the most recent token stays valid');
    }

    public function testValidTokenChangesPasswordAndIsConsumed(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens->createToken($this->userId, hash('sha256', $token));
        $before = $this->currentHash();

        $result = $this->service->resetPassword($token, 'NewSecret123', 'NewSecret123');
        $this->assertTrue($result['success']);

        $after = $this->currentHash();
        $this->assertNotSame($before, $after);
        $this->assertTrue(password_verify('NewSecret123', $after), 'new password verifies against stored hash');
        $this->assertSame(0, $this->countValidTokens(), 'token consumed');

        $reuse = $this->service->resetPassword($token, 'Another123', 'Another123');
        $this->assertFalse($reuse['success'], 'a consumed token cannot be reused');
        $this->assertSame($after, $this->currentHash());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            "INSERT INTO op_password_resets (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, DATE_SUB(NOW(6), INTERVAL 1 HOUR))",
            ['uid' => $this->userId, 'hash' => hash('sha256', $token)]
        );
        $before = $this->currentHash();

        $result = $this->service->resetPassword($token, 'NewSecret123', 'NewSecret123');
        $this->assertFalse($result['success']);
        $this->assertSame($before, $this->currentHash(), 'expired token must not change the password');
    }

    public function testShortOrMismatchedPasswordRejected(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens->createToken($this->userId, hash('sha256', $token));
        $before = $this->currentHash();

        $this->assertFalse($this->service->resetPassword($token, 'short', 'short')['success']);
        $this->assertFalse($this->service->resetPassword($token, 'GoodPassword1', 'Different1')['success']);
        $this->assertSame($before, $this->currentHash(), 'invalid input leaves the password unchanged');
        $this->assertSame(1, $this->countValidTokens(), 'token remains usable after a rejected attempt');
    }

    /**
     * Regression test for the race-condition fix (issue #546).
     *
     * The pre-fix flow did `findValidByHash()` (SELECT) → `updatePassword()`
     * → `markUsed()` (UPDATE) as three separate SQL statements with PHP
     * logic in between. Two parallel requests carrying the same token could
     * both pass the SELECT before either reached the UPDATE, both change
     * the password, and both send a "password changed" notification.
     *
     * The fix replaces that pattern with a single atomic
     * `UPDATE ... WHERE used_at IS NULL` whose affected-row count is the
     * concurrency arbiter. This test verifies the atomicity guarantee at
     * the repository level: a second claim on the same hash returns null,
     * and a subsequent resetPassword() call with the same token is rejected
     * without mutating the password.
     *
     * Credit: Nahid Hasan (responsible disclosure).
     */
    public function testClaimByHashIsAtomicAndSingleUse(): void
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->tokens->createToken($this->userId, $hash);
        $before = $this->currentHash();

        // First claim wins - simulates the winner of the race.
        $first = $this->tokens->claimByHash($hash);
        $this->assertNotNull($first, 'First claim must succeed');
        $this->assertSame($this->userId, (int) $first['user_id']);

        // Second claim loses - token already consumed by the first claim.
        $second = $this->tokens->claimByHash($hash);
        $this->assertNull($second, 'Second claim must fail (token already consumed)');

        // And the service-level resetPassword must also fail, leaving the
        // password exactly as it was before the race.
        $result = $this->service->resetPassword($token, 'NewSecret123', 'NewSecret123');
        $this->assertFalse($result['success'], 'resetPassword must reject a token claimed by a concurrent request');
        $this->assertSame($before, $this->currentHash(), 'loser of the race must not mutate the password');
        $this->assertSame(0, $this->countValidTokens(), 'token remains consumed after the race');
    }

    /**
     * Verifies that a failed resetPassword() (e.g. password too short) does
     * NOT consume the token - the user can retry with the same link using a
     * valid password. This is the fail-open guarantee that complements the
     * fail-closed atomic-claim guarantee above.
     *
     * Credit: Nahid Hasan (responsible disclosure - motivated this contract
     * being made explicit in the test suite).
     */
    public function testRejectedPasswordDoesNotConsumeToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->tokens->createToken($this->userId, $hash);

        // Failed attempt - password too short.
        $this->assertFalse($this->service->resetPassword($token, 'short', 'short')['success']);

        // Token must still be usable.
        $this->assertSame(1, $this->countValidTokens(), 'token survives a rejected attempt');

        // Now a valid attempt must succeed - proving the token was not consumed.
        $result = $this->service->resetPassword($token, 'ValidNewPass1', 'ValidNewPass1');
        $this->assertTrue($result['success'], 'token remains usable after a rejected attempt');
        $this->assertSame(0, $this->countValidTokens(), 'token consumed by the successful attempt');
    }
}
