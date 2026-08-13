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
     * Token type claim values. The `typ` claim distinguishes short-lived access
     * tokens (accepted by JwtAuthMiddleware for API authorization) from
     * long-lived refresh tokens (only accepted by the /auth/refresh endpoint to
     * mint a new access token). Without this distinction a stolen refresh token
     * would grant 30 days of direct API access — see audit finding SEC-1.
     */
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /**
     * Default audience claim for OwnPay mobile/companion-app tokens.
     */
    public const AUDIENCE = 'ownpay-mobile';

    /**
     * @var string The symmetric HMAC-SHA256 signature key.
     */
    private string $secret;

    /**
     * @var string The issuer identifier claiming token origin (iss claim).
     */
    private string $issuer;

    /**
     * @var string The expected audience claim (aud claim) for tokens issued
     *             by this service. Centralised here so decode()/verify() can
     *             validate the aud claim in addition to the signature and exp.
     */
    private string $audience;

    /**
     * @var int Default Time To Live (TTL) of issued tokens in seconds.
     */
    private int $ttl;

    /**
     * JwtService constructor.
     *
     * Resolves the token verification secret from runtime configurations.
     *
     * SECURITY: there is NO default-secret fallback. A previously-committed
     * fallback string ('default-secret-placeholder-for-test-suite-...') was
     * publicly known and would have allowed JWT forgery against any
     * misconfigured production instance that happened to set APP_ENV=testing
     * without setting JWT_SECRET. The test suite now explicitly provides
     * JWT_SECRET via phpunit.xml (<env name="JWT_SECRET" .../>), so the
     * fallback is unnecessary and has been removed. If JWT_SECRET is missing
     * or empty in ANY environment (including testing), a RuntimeException
     * is thrown with a clear message.
     *
     * @param string|null $secret Optional override secret key.
     * @param string|null $issuer Optional override issuer parameter.
     * @param string|null $audience Optional override audience parameter.
     * @param int $ttl Default expiry lifetime of tokens.
     * @throws \RuntimeException If the configured JWT_SECRET is empty/invalid.
     */
    public function __construct(?string $secret = null, ?string $issuer = null, ?string $audience = null, int $ttl = 86400)
    {
        $resolvedSecret = $secret;
        if ($resolvedSecret === null) {
            $resolvedSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
        }
        if (!is_string($resolvedSecret) || trim($resolvedSecret) === '') {
            throw new \RuntimeException('JWT_SECRET must be set; got empty value.');
        }

        $this->secret = $resolvedSecret;
        // Default to the stable ISSUER constant — never APP_NAME (see ISSUER doc). An explicit override
        // is still honored (tests), but production wiring passes none so the issuer is brand-independent.
        $this->issuer = $issuer ?? self::ISSUER;
        $this->audience = $audience ?? self::AUDIENCE;
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
            'typ' => self::TYPE_ACCESS,
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
            'typ'      => self::TYPE_ACCESS,
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
     * and general parsing syntax failures. Also validates the issuer (iss)
     * and audience (aud) claims centrally so any caller of this method is
     * protected against tokens minted for a different issuer/audience,
     * even if JwtAuthMiddleware's manual checks were removed.
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

        // Centralized claim validation: iss and aud must match this service's
        // configured issuer and audience. This protects any caller of decode()
        // (not just JwtAuthMiddleware) from accepting cross-issuer/audience
        // tokens.
        if (!$this->claimsMatch($decoded)) {
            return [
                'valid'   => false,
                'error'   => 'invalid_claims',
                'payload' => null,
            ];
        }

        return [
            'valid'   => true,
            'error'   => null,
            'payload' => $decoded,
        ];
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
     * Same centralised claim validation as decode(): in addition to the
     * signature and exp checks performed by JWT::decode, this method now
     * also verifies the iss and aud claims match this service's configured
     * issuer/audience. Any caller of verify() is therefore protected against
     * cross-issuer/audience tokens, regardless of whether they perform their
     * own claim checks afterwards.
     *
     * @param string $token The raw token parameter.
     * @return array<string, mixed> The associative representation of the decoded claims.
     * @throws \RuntimeException If the token signature is invalid, tampered,
     *                           expired, or has mismatched iss/aud claims.
     */
    public function verify(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new \RuntimeException('Token expired', 401, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid token', 401, $e);
        }

        if (!$this->claimsMatch($decoded)) {
            throw new \RuntimeException('Invalid token claims', 401);
        }

        return (array) $decoded;
    }

    /**
     * Validates the iss and aud claims of a decoded JWT against the service's
     * configured issuer and audience.
     *
     * The aud claim may be either a string (single audience) or an array of
     * strings (multi-audience, per RFC 7519 §4.1.3). The token is considered
     * valid if the configured audience is present in the claim.
     *
     * @param object $decoded The decoded JWT payload (stdClass from JWT::decode).
     * @return bool True if both iss and aud match the configured values.
     */
    private function claimsMatch(object $decoded): bool
    {
        $iss = $decoded->iss ?? null;
        if (!is_string($iss) || $iss !== $this->issuer) {
            return false;
        }

        $aud = $decoded->aud ?? null;
        if (is_string($aud)) {
            return $aud === $this->audience;
        }
        if (is_array($aud)) {
            foreach ($aud as $entry) {
                if (is_string($entry) && $entry === $this->audience) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * Issues a long-lived refresh token associated with the device context.
     *
     * The token carries `typ=refresh` so JwtAuthMiddleware can reject it for
     * direct API access — refresh tokens are only usable at the /auth/refresh
     * endpoint to mint a new short-lived access token. A stolen refresh token
     * therefore cannot be used as a long-lived access credential.
     *
     * @param int $userId Primary user ID.
     * @param int $merchantId Active merchant context.
     * @param string $deviceId Unique companion hardware ID.
     * @return string Encoded JWT string representing the refresh token.
     */
    public function issueRefreshToken(int $userId, int $merchantId, string $deviceId): string
    {
        $now = time();
        $ttl = 2592000; // 30 days
        $payload = [
            'iss' => $this->issuer,
            'aud' => 'ownpay-mobile',
            'sub' => $userId,
            'mid' => $merchantId,
            'did' => $deviceId,
            'typ' => self::TYPE_REFRESH,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }
}
