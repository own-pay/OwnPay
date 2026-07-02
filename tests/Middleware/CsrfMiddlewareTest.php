<?php

declare(strict_types=1);

namespace Tests\Middleware;

use OwnPay\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_ENV['APP_HMAC_SECRET'] = '';

        $this->previousErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'csrf-test'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        $_SESSION = [];
        $_POST    = [];
        unset($_ENV['APP_HMAC_SECRET']);
    }

    public function testCsrfValidatesMatchingTokens(): void
    {
        $token = 'abc123session';
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token']    = $token;

        $result = (new CsrfMiddleware())->validate('');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertSame($token, $result['newToken']);
    }

    public function testCsrfRejectsMismatchedTokens(): void
    {
        $_SESSION['_csrf_token'] = 'expected-token';
        $_POST['_csrf_token']    = 'attacker-token';

        $result = (new CsrfMiddleware())->validate('');

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid request token', $result['error']);
        $this->assertNotNull($result['newToken']);
        $this->assertNotSame('expected-token', $result['newToken']);
        $this->assertNotSame('attacker-token', $result['newToken']);
    }

    public function testCsrfRejectsMissingPostToken(): void
    {
        $_SESSION['_csrf_token'] = 'abc';

        $result = (new CsrfMiddleware())->validate('');

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid request token', $result['error']);
    }

    public function testCsrfRejectsMissingSessionToken(): void
    {
        $_POST['_csrf_token'] = 'abc';

        $result = (new CsrfMiddleware())->validate('');

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid request token', $result['error']);
    }

    public function testCsrfRotatesSessionTokenOnFailure(): void
    {
        $_SESSION['_csrf_token'] = 'old-token';
        $_POST['_csrf_token']    = 'wrong';

        (new CsrfMiddleware())->validate('');

        $this->assertNotSame('old-token', $_SESSION['_csrf_token']);
        $this->assertNotEmpty($_SESSION['_csrf_token']);
        $this->assertSame(64, strlen($_SESSION['_csrf_token']));
    }

    public function testHmacRejectsExpiredTimestamp(): void
    {
        $_ENV['APP_HMAC_SECRET'] = 'shared-secret';
        $_POST['op-app-id']        = 'client-1';
        $_POST['op-app-timestamp'] = (string) (time() - 600);
        $_POST['action']           = 'do-thing';

        $result = (new CsrfMiddleware())->validate('any-token');

        $this->assertFalse($result['valid']);
        $this->assertSame('Request expired. Please try again.', $result['error']);
    }

    public function testHmacRejectsNonNumericTimestamp(): void
    {
        $_ENV['APP_HMAC_SECRET'] = 'shared-secret';
        $_POST['op-app-id']        = 'client-1';
        $_POST['op-app-timestamp'] = 'not-a-number';
        $_POST['action']           = 'do-thing';

        $result = (new CsrfMiddleware())->validate('any-token');

        $this->assertFalse($result['valid']);
        $this->assertSame('Request expired. Please try again.', $result['error']);
    }

    public function testHmacAcceptsValidSignature(): void
    {
        $secret    = 'shared-secret';
        $appId     = 'client-1';
        $timestamp = (string) time();
        $action    = 'do-thing';

        $_ENV['APP_HMAC_SECRET']    = $secret;
        $_POST['op-app-id']         = $appId;
        $_POST['op-app-timestamp']  = $timestamp;
        $_POST['action']            = $action;

        $expected = hash_hmac('sha256', "{$appId}|{$timestamp}|{$action}", $secret);

        $result = (new CsrfMiddleware())->validate($expected);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testHmacRejectsInvalidSignature(): void
    {
        $_ENV['APP_HMAC_SECRET']    = 'shared-secret';
        $_POST['op-app-id']         = 'client-1';
        $_POST['op-app-timestamp']  = (string) time();
        $_POST['action']            = 'do-thing';

        $result = (new CsrfMiddleware())->validate('wrong-signature');

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid request token', $result['error']);
    }

    public function testHmacBindsSignatureToAction(): void
    {
        $secret    = 'shared-secret';
        $appId     = 'client-1';
        $timestamp = (string) time();

        $_ENV['APP_HMAC_SECRET']    = $secret;
        $_POST['op-app-id']         = $appId;
        $_POST['op-app-timestamp']  = $timestamp;
        $_POST['action']            = 'transfer-money';

        // Signature for a different action - replay protection
        $signatureForOtherAction = hash_hmac('sha256', "{$appId}|{$timestamp}|view-balance", $secret);

        $result = (new CsrfMiddleware())->validate($signatureForOtherAction);

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid request token', $result['error']);
    }

    public function testHmacRejectsMissingAppFields(): void
    {
        $secret = 'shared-secret';

        $_ENV['APP_HMAC_SECRET'] = $secret;
        $_POST['pp-app-id']        = 'op-client';
        $_POST['pp-app-timestamp'] = (string) time();
        $_POST['action']           = 'test-action';

        $expected = hash_hmac('sha256', "op-client|" . $_POST['pp-app-timestamp'] . "|test-action", $secret);

        $result = (new CsrfMiddleware())->validate($expected);

        $this->assertFalse($result['valid']);
    }

    public function testCsrfRejectsNonStringTokensGracefully(): void
    {
        $_SESSION['_csrf_token'] = 'expected-token';
        $_POST['_csrf_token']    = ['array', 'values'];

        $result = (new CsrfMiddleware())->validate('');

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid request token', $result['error']);
    }

    public function testHmacRejectsNonStringParametersGracefully(): void
    {
        $_ENV['APP_HMAC_SECRET'] = 'shared-secret';
        $_POST['op-app-id']        = ['array', 'values'];
        $_POST['op-app-timestamp'] = (string) time();
        $_POST['action']           = 'do-thing';

        $result = (new CsrfMiddleware())->validate('any-token');

        $this->assertFalse($result['valid']);
        $this->assertSame('Request expired. Please try again.', $result['error']);
    }
}
