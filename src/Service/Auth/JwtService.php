<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT service — issue and verify tokens for mobile companion app.
 *
 * Claims: sub (user_id), mid (merchant_id), did (device_id), exp, iat, iss.
 */
final class JwtService
{
    private string $secret;
    private string $issuer;
    private int $ttl;

    public function __construct(?string $secret = null, string $issuer = 'ownpay', int $ttl = 86400)
    {
        $this->secret = $secret ?? (getenv('JWT_SECRET') ?: '');
        $this->issuer = $issuer;
        $this->ttl = $ttl;

        if ($this->secret === '') {
            throw new \RuntimeException('JWT_SECRET not configured');
        }
    }

    /**
     * Issue JWT for device.
     */
    public function issue(int $userId, int $merchantId, string $deviceId, ?int $ttl = null): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'sub' => $userId,
            'mid' => $merchantId,
            'did' => $deviceId,
            'iat' => $now,
            'exp' => $now + ($ttl ?? $this->ttl),
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
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
