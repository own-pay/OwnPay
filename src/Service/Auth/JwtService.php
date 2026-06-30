<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * OwnPay JSON Web Token (JWT) Service.
 *
 * Handles the generation, encoding, decoding, and verification of JSON Web Tokens (JWT)
 * utilized for authenticating the mobile companion application communicating with the API.
 * Uses the Firebase JWT library, enforcing HS256 sign verification protocols.
 *
 * @package OwnPay\Service\Auth
 */
final class JwtService
{
    public const ISSUER = 'OwnPay';

    /**
     * @var string The symmetric HMAC-SHA256 signature key.
     */
    private string $secret;

    /**
     * @var string The issuer identifier claiming token origin (iss claim).
     */
    private string $issuer;

    /**
     * @var int Default Time To Live (TTL) of issued tokens in seconds.
     */
    private int $ttl;

    /**
     * JwtService constructor.
     *
     * Resolves the token verification secret from runtime configurations. Fallbacks
     * to a test-suite safe mock key if the application is running within unit tests.
     *
     * @param string|null $secret Optional override secret key.
     * @param string|null $issuer Optional override issuer parameter.
     * @param int $ttl Default expiry lifetime of tokens.
     * @throws \RuntimeException If the configured JWT_SECRET is empty/invalid in production.
     */
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
        // Default to the stable ISSUER constant — never APP_NAME (see ISSUER doc). An explicit override
        // is still honored (tests), but production wiring passes none so the issuer is brand-independent.
        $this->issuer = $issuer ?? self::ISSUER;
        $this->ttl = $ttl;
    }

    /**
     * Generates a cryptographically secure 256-bit secret key encoded in hexadecimal.
     *
     * @return string Hexadecimal encoded key.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Issues an authenticated JWT for a paired companion device.
     *
     * Sets standard and custom claims including issuer, subject, audience, expiration,
     * issuance timestamp, target brand ID, and unique device identifier.
     *
     * @param int $userId The primary identifier of the system administrator/user.
     * @param int $merchantId The primary merchant/brand context identifier.
     * @param string $deviceId The registered hardware/app device identifier.
     * @param int|null $ttl Custom lifetime in seconds.
     * @return string Encoded JWT string.
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
     * Encodes a scoped device token and returns it with its expiry metadata.
     *
     * @param string $deviceUuid The companion device registered UUID.
     * @param int $brandId The system brand/merchant owner identifier.
     * @param string[] $scopes The array of authorization scopes allowed.
     * @param int $ttl Lifetime duration of the generated token.
     * @return array{token: string, expires_at: int, expires_in: int}
     */
    public function encode(string $deviceUuid, int $brandId, array $scopes = [], int $ttl = 900): array
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
        $token = JWT::encode($payload, $this->secret, 'HS256');
        return [
            'token'      => $token,
            'expires_at' => $now + $ttl,
            'expires_in' => $ttl,
        ];
    }

    /**
     * Decodes and validates a provided JWT payload structure.
     *
     * Handles signature mismatch validations, expired token assertions,
     * and general parsing syntax failures.
     *
     * @param string $token The input JWT string.
     * @return array{valid: bool, error: string|null, payload: object|null}
     */
    public function decode(string $token): array
    {
        if ($token === '') {
            return ['valid' => false, 'error' => 'EMPTY_TOKEN', 'payload' => null];
        }
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
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
     * Extracts the raw companion device UUID string from a formatted subject claim.
     *
     * @param string $sub The JWT subject string.
     * @return string|null The extracted device UUID, or null if invalid formatting.
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
     * Verifies the authenticity and validity of a JWT string.
     *
     * @param string $token The raw token parameter.
     * @return array<string, mixed> The associative representation of the decoded claims.
     * @throws \RuntimeException If the token signature is invalid, tampered, or expired.
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
     * Issues a long-lived refresh token associated with the device context.
     *
     * @param int $userId Primary user ID.
     * @param int $merchantId Active merchant context.
     * @param string $deviceId Unique companion hardware ID.
     * @return string Encoded JWT string representing the refresh token.
     */
    public function issueRefreshToken(int $userId, int $merchantId, string $deviceId): string
    {
        return $this->issue($userId, $merchantId, $deviceId, 2592000); // 30 days
    }
}
