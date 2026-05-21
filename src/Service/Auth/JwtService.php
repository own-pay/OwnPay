<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT service — issue and verify tokens for mobile companion app.
 *
 * Claims: sub (user_id/device_id), mid (merchant_id), did (device_id), exp, iat, iss.
 */
final class JwtService
{
    private string $secret;
    private string $issuer;
    private int $ttl;

    public function __construct(?string $secret = null, ?string $issuer = null, int $ttl = 86400)
    {
        $resolvedSecret = $secret;
        if ($resolvedSecret === null) {
            $resolvedSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
        }
        if (!is_string($resolvedSecret) || trim($resolvedSecret) === '') {
            $isTestEnv = (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'testing');
            if ($isTestEnv) {
                $resolvedSecret = 'default-secret-placeholder-for-test-suite-32-chars-long';
            } else {
                throw new \RuntimeException('JWT_SECRET must be configured and non-empty.');
            }
        }

        $this->secret = $resolvedSecret;
        $this->issuer = $issuer ?? (getenv('APP_NAME') ?: 'OwnPay');
        $this->ttl = $ttl;
    }

    /**
     * Generate secure hex secret.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Issue JWT for device.
     */
    public function issue(int $userId, int $merchantId, string $deviceId, ?int $ttl = null): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'aud' => 'ownpay-mobile',
            'sub' => $userId,
            'mid' => $merchantId,
            'did' => $deviceId,
            'iat' => $now,
            'exp' => $now + ($ttl ?? $this->ttl),
            'jti' => bin2hex(random_bytes(8)),
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Encode method for compatibility with test assertions.
     */
    public function encode(string $deviceUuid, int $brandId, ?string $secret = null, array $scopes = [], int $ttl = 900): array
    {
        $now = time();
        $payload = [
            'iss'      => $this->issuer,
            'aud'      => 'ownpay-mobile',
            'sub'      => 'device:' . $deviceUuid,
            'mid'      => $brandId,
            'did'      => $deviceUuid,
            'brand_id' => $brandId,
            'scopes'   => $scopes,
            'iat'      => $now,
            'exp'      => $now + $ttl,
            'jti'      => bin2hex(random_bytes(8)),
        ];
        $key = $secret ?? $this->secret;
        $token = JWT::encode($payload, $key, 'HS256');
        return [
            'token'      => $token,
            'expires_at' => $now + $ttl,
            'expires_in' => $ttl,
        ];
    }

    /**
     * Decode method for compatibility with test assertions.
     */
    public function decode(string $token, ?string $secret = null): array
    {
        if ($token === '') {
            return ['valid' => false, 'error' => 'EMPTY_TOKEN', 'payload' => null];
        }
        try {
            $key = $secret ?? $this->secret;
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            return [
                'valid'   => true,
                'error'   => null,
                'payload' => $decoded,
            ];
        } catch (\Firebase\JWT\ExpiredException $e) {
            return [
                'valid'   => false,
                'error'   => 'TOKEN_EXPIRED',
                'payload' => null,
            ];
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            return [
                'valid'   => false,
                'error'   => 'INVALID_SIGNATURE',
                'payload' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'valid'   => false,
                'error'   => 'INVALID_TOKEN',
                'payload' => null,
            ];
        }
    }

    /**
     * Extract device UUID from subject string.
     */
    public function extractDeviceUuid(string $sub): ?string
    {
        if (str_starts_with($sub, 'device:')) {
            $uuid = substr($sub, 7);
            return $uuid !== '' ? $uuid : null;
        }
        return null;
    }

    /**
     * Verify and decode JWT.
     * @return array{sub: int, mid: int, did: string, exp: int, iat: int, iss: string}
     * @throws \RuntimeException
     */
    public function verify(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            return (array) $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new \RuntimeException('Token expired', 401, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid token', 401, $e);
        }
    }

    /**
     * Issue refresh token (longer TTL).
     */
    public function issueRefreshToken(int $userId, int $merchantId, string $deviceId): string
    {
        return $this->issue($userId, $merchantId, $deviceId, 2592000); // 30 days
    }
}
