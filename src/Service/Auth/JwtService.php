<?php

declare(strict_types=1);

namespace OwnPay\Service\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

/**
 * JwtService — Encode, decode, and validate JWTs for mobile companion devices.
 *
 * Each paired device has its own HMAC-SHA256 secret (`jwt_secret` in `op_paired_devices`),
 * so a compromised token from one device cannot be used on another.
 *
 * Access tokens are short-lived (15 min). Refresh tokens are opaque strings
 * managed by DevicePairingService.
 */
final class JwtService
{
    private const ALGORITHM = 'HS256';
    private const ISSUER    = 'ownpay';

    /** Default access token lifetime: 15 minutes */
    private const DEFAULT_TTL = 900;

    /**
     * Encode a JWT access token for a paired device.
     *
     * @param string $deviceUuid  The device's UUID (becomes `sub`)
     * @param int    $brandId     The brand this device belongs to
     * @param string $jwtSecret   Per-device HMAC secret (hex string)
     * @param array  $scopes      Allowed scopes (e.g. ['sms:submit', 'dashboard:read'])
     * @param int    $ttl         Token lifetime in seconds (default 900)
     * @return array{token: string, expires_at: int}
     */
    public function encode(
        string $deviceUuid,
        int    $brandId,
        string $jwtSecret,
        array  $scopes = ['sms:submit', 'dashboard:read', 'notifications:poll'],
        int    $ttl = self::DEFAULT_TTL
    ): array {
        $now = time();
        $exp = $now + $ttl;

        $payload = [
            'sub'      => "device:{$deviceUuid}",
            'iss'      => self::ISSUER,
            'iat'      => $now,
            'exp'      => $exp,
            'brand_id' => $brandId,
            'scopes'   => $scopes,
        ];

        $token = JWT::encode($payload, $jwtSecret, self::ALGORITHM);

        return [
            'token'      => $token,
            'expires_at' => $exp,
            'expires_in' => $ttl,
        ];
    }

    /**
     * Decode and validate a JWT access token.
     *
     * @param string $token     The raw JWT string
     * @param string $jwtSecret The per-device HMAC secret
     * @return array{valid: bool, payload: ?object, error: ?string}
     */
    public function decode(string $token, string $jwtSecret): array
    {
        try {
            $decoded = JWT::decode($token, new Key($jwtSecret, self::ALGORITHM));

            // Verify issuer
            if (($decoded->iss ?? '') !== self::ISSUER) {
                return [
                    'valid'   => false,
                    'payload' => null,
                    'error'   => 'Invalid token issuer.',
                ];
            }

            return [
                'valid'   => true,
                'payload' => $decoded,
                'error'   => null,
            ];
        } catch (ExpiredException $e) {
            return [
                'valid'   => false,
                'payload' => null,
                'error'   => 'TOKEN_EXPIRED',
            ];
        } catch (SignatureInvalidException $e) {
            return [
                'valid'   => false,
                'payload' => null,
                'error'   => 'INVALID_SIGNATURE',
            ];
        } catch (\Throwable $e) {
            return [
                'valid'   => false,
                'payload' => null,
                'error'   => 'INVALID_TOKEN',
            ];
        }
    }

    /**
     * Extract the device UUID from a decoded JWT subject claim.
     *
     * @param string $sub The `sub` claim value (e.g. "device:abc-123")
     * @return string|null The UUID portion, or null if malformed
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
     * Generate a cryptographically secure per-device HMAC secret.
     *
     * @return string 64-char hex string (256-bit key)
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
