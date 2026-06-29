<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Customer\ApiKeyService;
use OwnPay\Repository\SettingsRepository;

/**
 * Class DeveloperController
 *
 * Coordinates rendering of the Developer Hub page, including listing API keys, presenting endpoint references,
 * verifying webhook delivery URLs via cURL tests, and configuring system rate limits.
 *
 * @package OwnPay\Controller\Admin
 */
final class DeveloperController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * DeveloperController constructor.
     *
     * @param Container    $c       The dependency injection container.
     * @param AdminSession $session The administrative session service.
     */
    public function __construct(Container $c, AdminSession $session)
    {
        $this->c       = $c;
        $this->session = $session;
    }

    /**
     * Renders the developer hub overview, fetching API keys, rate limit specifications,
     * webhook secrets, and populating API reference lists.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The developer hub page response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        // All Brands view manages platform-owned API keys; a brand view manages its own.
        $mid = $brand->getWriteMerchantId();

        $apiKeySvc = $this->c->get(ApiKeyService::class);
        if (!$apiKeySvc instanceof ApiKeyService) {
            throw new \RuntimeException('ApiKeyService service unavailable');
        }
        $apiKeys = $apiKeySvc->list($mid);

        $settings = $this->c->get(SettingsRepository::class);
        if (!$settings instanceof SettingsRepository) {
            throw new \RuntimeException('SettingsRepository service unavailable');
        }
        $webhookUrlVal    = $settings->get('general', 'webhook_url', '');
        $webhookUrl       = is_string($webhookUrlVal) ? $webhookUrlVal : '';
        $webhookSecretVal = $settings->get('general', 'webhook_secret', '');
        $webhookSecret    = is_string($webhookSecretVal) ? $webhookSecretVal : '';
        $apiRateLimitVal  = $settings->get('general', 'api_rate_limit', '60');
        $apiRateLimit     = is_string($apiRateLimitVal) ? $apiRateLimitVal : '60';
        $whitelistVal     = $settings->get('general', 'rate_limit_whitelist_ips', '');
        $whitelist        = is_string($whitelistVal) ? $whitelistVal : '';
        $rulesJsonVal     = $settings->get('general', 'rate_limit_rules', '[]');
        $rulesJson        = is_string($rulesJsonVal) ? $rulesJsonVal : '[]';
        $rules            = json_decode($rulesJson, true);
        if (!is_array($rules)) {
            $rules = [];
        }
        $baseUrlVal       = $settings->get('general', 'base_url', '');
        $baseUrl          = is_string($baseUrlVal) ? $baseUrlVal : '';

        if (empty($baseUrl)) {
            $baseUrl = ($req->isSecure() ? 'https' : 'http') . '://' . ($req->header('Host') ?: 'localhost');
        }

        // All public API endpoints for reference
        $endpoints = $this->getEndpointReference($baseUrl);

        // Fetch active rate limits from database
        $rateLimitRepo = $this->c->get(\OwnPay\Repository\RateLimitRepository::class);
        if (!$rateLimitRepo instanceof \OwnPay\Repository\RateLimitRepository) {
            throw new \RuntimeException('RateLimitRepository service unavailable');
        }
        $db = $rateLimitRepo->getDatabase();
        $now = time();
        $activeLimits = $db->fetchAll(
            "SELECT * FROM op_rate_limits WHERE expires_at > :now ORDER BY expires_at ASC LIMIT 100",
            ['now' => $now]
        );

        // Fetch multiple distinct webhooks
        $webhookRepo = $this->c->get(\OwnPay\Repository\WebhookRepository::class);
        if (!$webhookRepo instanceof \OwnPay\Repository\WebhookRepository) {
            throw new \RuntimeException('WebhookRepository service unavailable');
        }
        $webhooksListPaginated = $webhookRepo->forTenant($mid)->paginate(1, 100);
        $webhooksList = $webhooksListPaginated['items'];

        // Decode events field for Twig compatibility
        $webhooksList = array_map(function (array $wh) {
            $evtVal = $wh['events'] ?? '[]';
            $decoded = json_decode(is_string($evtVal) ? $evtVal : '[]', true);
            $wh['decoded_events'] = is_array($decoded) ? $decoded : [];
            return $wh;
        }, $webhooksList);



        // Consume one-time generated API key from session (shown once, then gone)
        $generatedKey = $_SESSION['_generated_api_key'] ?? null;
        $generatedKeyLabel = $_SESSION['_generated_api_key_label'] ?? '';
        unset($_SESSION['_generated_api_key'], $_SESSION['_generated_api_key_label']);

        return $this->renderAdminPage('admin/developer/index.twig', [
            'api_keys'                 => $apiKeys,
            'webhook_url'              => $webhookUrl,
            'webhook_secret'           => $webhookSecret,
            'api_rate_limit'           => $apiRateLimit,
            'rate_limit_whitelist_ips' => $whitelist,
            'rate_limit_rules'         => $rules,
            'base_url'                 => rtrim($baseUrl, '/'),
            'endpoints'                => $endpoints,
            'active_page'              => 'developer',
            'generated_key'            => $generatedKey,
            'generated_key_label' => $generatedKeyLabel,
            'active_limits'       => $activeLimits,
            'now'                 => $now,
            'webhooks'            => $webhooksList,
        ]);
    }

    /**
     * Triggers a cURL test POST payload to the configured webhook delivery target.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response A JSON formatted feedback response.
     */
    public function webhookTest(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);

        $settings = $this->c->get(SettingsRepository::class);
        if (!$settings instanceof SettingsRepository) {
            throw new \RuntimeException('SettingsRepository service unavailable');
        }
        $webhookUrlVal = $settings->get('general', 'webhook_url', '');
        $webhookUrl = is_string($webhookUrlVal) ? $webhookUrlVal : '';

        if (empty($webhookUrl)) {
            return Response::json(['success' => false, 'error' => 'No webhook URL configured']);
        }

        // Resolve and pin a validated public IP. This both rejects private/loopback
        // targets AND closes the DNS-rebinding window: without pinning, the HTTP
        // client would re-resolve the host at send time and a malicious resolver
        // could return a public IP at check time and an internal one at request time.
        $pinnedIp = \OwnPay\Security\UrlValidator::resolveSafeWebhookIp($webhookUrl);
        if ($pinnedIp === null) {
            return Response::json(['success' => false, 'error' => 'Invalid webhook URL']);
        }

        $parsed = parse_url($webhookUrl);
        $host = is_array($parsed) && isset($parsed['host']) ? (string) $parsed['host'] : '';
        $port = is_array($parsed) && isset($parsed['port']) ? (int) $parsed['port'] : 443;

        $payload = json_encode([
            'event'     => 'webhook.test',
            'timestamp' => time(),
            'data'      => ['message' => 'OwnPay webhook test event'],
        ]);

        if (!is_string($payload)) {
            return Response::json(['success' => false, 'error' => 'Failed to serialize webhook payload']);
        }

        $secretVal = $settings->get('general', 'webhook_secret', '');
        $secret = is_string($secretVal) ? $secretVal : '';
        $sig    = hash_hmac('sha256', $payload, $secret);

        $ch = curl_init($webhookUrl);
        $curlOptions = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Never follow redirects: a 30x to an internal host would otherwise
            // bypass the pinned-IP SSRF guard.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-OwnPay-Signature: sha256=' . $sig,
                'X-OwnPay-Event: webhook.test',
            ],
        ];
        // Pin host -> validated public IP (TLS SNI/cert validation still uses the hostname).
        if ($host !== '') {
            $curlOptions[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinnedIp}"];
        }
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return Response::json(['success' => false, 'error' => $curlErr]);
        }

        return Response::json([
            'success'     => $httpCode >= 200 && $httpCode < 300,
            'http_status' => $httpCode,
            'response'    => mb_substr((string) $response, 0, 500),
        ]);
    }

    /**
     * Internal endpoint registry definition for the developer hub documentation.
     *
     * @param string $baseUrl The API host base path.
     *
     * @return array<string, list<array{method:string, path:string, auth:string, desc:string}>>
     */
    private function getEndpointReference(string $baseUrl): array
    {
        return [
            'Health' => [
                ['method' => 'GET',    'path' => '/api/v1/health',                          'auth' => 'None',   'desc' => 'Health check - returns system status, version, and DB connectivity'],
            ],
            'Payments' => [
                ['method' => 'POST',   'path' => '/api/v1/payments',                        'auth' => 'Bearer', 'desc' => 'Initiate a new payment intent. Returns checkout_url and payment_id'],
                ['method' => 'GET',    'path' => '/api/v1/payments/{payment_id}',           'auth' => 'Bearer', 'desc' => 'Get payment intent status by payment intent UUID'],
            ],
            'Transactions' => [
                ['method' => 'GET',    'path' => '/api/v1/transactions',                    'auth' => 'Bearer', 'desc' => 'List transactions (paginated). Supports ?page=1&per_page=20&status=completed'],
                ['method' => 'GET',    'path' => '/api/v1/transactions/{trx_id}',            'auth' => 'Bearer', 'desc' => 'Get single transaction detail by transaction ID'],
            ],
            'Refunds' => [
                ['method' => 'POST',   'path' => '/api/v1/refunds',                         'auth' => 'Bearer', 'desc' => 'Create a refund for a completed transaction'],
                ['method' => 'GET',    'path' => '/api/v1/refunds/{trx_id}',                'auth' => 'Bearer', 'desc' => 'Get refund status by transaction ID'],
            ],
            'Customers' => [
                ['method' => 'GET',    'path' => '/api/v1/customers',                       'auth' => 'Bearer', 'desc' => 'List customers (paginated)'],
                ['method' => 'POST',   'path' => '/api/v1/customers',                       'auth' => 'Bearer', 'desc' => 'Create a new customer record'],
                ['method' => 'GET',    'path' => '/api/v1/customers/{identifier}',          'auth' => 'Bearer', 'desc' => 'Get customer details by ID or email/phone identifier'],
            ],
            'API Keys' => [
                ['method' => 'GET',    'path' => '/api/v1/api-keys',                        'auth' => 'Bearer', 'desc' => 'List API keys for current brand'],
                ['method' => 'POST',   'path' => '/api/v1/api-keys',                        'auth' => 'Bearer', 'desc' => 'Generate a new API key'],
                ['method' => 'DELETE', 'path' => '/api/v1/api-keys/{id}',                    'auth' => 'Bearer', 'desc' => 'Revoke an API key immediately'],
            ],
            'Webhooks' => [
                ['method' => 'POST',   'path' => '/api/v1/webhooks/tests',                  'auth' => 'Bearer', 'desc' => 'Send a test event to your webhook URL'],
                ['method' => 'GET',    'path' => '/api/v1/webhooks/deliveries',              'auth' => 'Bearer', 'desc' => 'List recent webhook delivery attempts with status'],
            ],
            'Webhooks (Incoming / IPN)' => [
                ['method' => 'POST',   'path' => '/webhook/{gateway}',                      'auth' => 'Sig',    'desc' => 'Unified IPN endpoint for gateway callbacks. Replace {gateway} with gateway slug (e.g. bkash-api, stripe)'],
            ],
            'Mobile Device API' => [
                ['method' => 'POST',   'path' => '/api/mobile/v1/devices',                  'auth' => 'OTP',    'desc' => 'Pair mobile device using 6-digit OTP from admin panel'],
                ['method' => 'POST',   'path' => '/api/mobile/v1/devices/heartbeats',        'auth' => 'JWT',    'desc' => 'Update device last-seen timestamp and heartbeat'],
                ['method' => 'DELETE', 'path' => '/api/mobile/v1/devices/{id}',              'auth' => 'JWT',    'desc' => 'Revoke device authorization by ID'],
                ['method' => 'POST',   'path' => '/api/mobile/v1/devices/bulk-revocations',  'auth' => 'JWT',    'desc' => 'Bulk revoke multiple mobile devices'],
                ['method' => 'POST',   'path' => '/api/mobile/v1/sms',                      'auth' => 'JWT',    'desc' => 'Submit encrypted SMS batch (max 20) for server-side parsing'],
                ['method' => 'GET',    'path' => '/api/mobile/v1/sms/queues',                'auth' => 'JWT',    'desc' => 'Retrieve outbound SMS messages queued for processing/delivery'],
                ['method' => 'GET',    'path' => '/api/mobile/v1/notifications',            'auth' => 'JWT',    'desc' => 'Poll for unread payment notifications'],
                ['method' => 'POST',   'path' => '/api/mobile/v1/notifications/acknowledgements', 'auth' => 'JWT', 'desc' => 'Acknowledge / mark notifications as read'],
                ['method' => 'GET',    'path' => '/api/mobile/v1/dashboard',                'auth' => 'JWT',    'desc' => 'Mobile dashboard summary - today totals, recent transactions'],
                ['method' => 'GET',    'path' => '/api/mobile/v1/config/filter-rules',      'auth' => 'JWT',    'desc' => 'Fetch configured filtering rules for mobile SMS privacy gate'],
                ['method' => 'POST',   'path' => '/api/mobile/v1/devices/token-refreshes',   'auth' => 'OTP/JWT', 'desc' => 'Refresh mobile API access token using refresh token'],
                ['method' => 'GET',    'path' => '/api/mobile/v1/devices/statuses',         'auth' => 'JWT',    'desc' => 'Get overall status and registration details of the paired device'],
            ],
        ];
    }

    /**
     * Resets a rate limit bucket by removing the corresponding database record.
     */
    public function resetLimit(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            $this->session->flashError("Permission denied. Only Super Admins can reset rate limits.");
            return Response::redirect('/admin/developer#rate-limits');
        }

        $keyVal = $req->post('key', '');
        $key = is_string($keyVal) ? trim($keyVal) : '';

        if ($key !== '') {
            $rateLimitRepo = $this->c->get(\OwnPay\Repository\RateLimitRepository::class);
            if ($rateLimitRepo instanceof \OwnPay\Repository\RateLimitRepository) {
                $db = $rateLimitRepo->getDatabase();
                $db->delete("DELETE FROM op_rate_limits WHERE key_name = :key", ['key' => $key]);

                // Also delete from Redis if active
                if ($this->c->has(\OwnPay\Cache\RedisCache::class)) {
                    try {
                        $cache = $this->c->get(\OwnPay\Cache\RedisCache::class);
                        if ($cache instanceof \OwnPay\Cache\RedisCache) {
                            $redis = $cache->redis();
                            $redis->del('op:' . $key);
                        }
                    } catch (\Throwable) {
                        // ignore Redis deletion failure
                    }
                }

                $this->session->flashSuccess("Rate limit bucket for '{$key}' reset successfully.");
            }
        }

        return Response::redirect('/admin/developer#rate-limits');
    }

    /**
     * Saves the rate limit whitelist and rules settings.
     *
     * Only accessible by Super Admins.
     */
    public function saveSettings(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            $this->session->flashError("Permission denied. Only Super Admins can configure rate limits.");
            return Response::redirect('/admin/developer#rate-limits');
        }

        $settings = $this->c->get(SettingsRepository::class);
        if (!$settings instanceof SettingsRepository) {
            throw new \RuntimeException('SettingsRepository service unavailable');
        }

        // 1. Save IP Whitelist
        $whitelistVal = $req->post('rate_limit_whitelist_ips', '');
        $whitelist = is_string($whitelistVal) ? trim($whitelistVal) : '';
        $settings->set('general', 'rate_limit_whitelist_ips', $whitelist);

        // 2. Parse and Validate Custom Rules
        $rulesPost = $req->post('rules');
        $cleanRules = [];
        if (is_array($rulesPost)) {
            foreach ($rulesPost as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $pathVal = $rule['path'] ?? '';
                $methodVal = $rule['method'] ?? 'ALL';
                $limitVal = $rule['limit'] ?? 0;
                $windowVal = $rule['window'] ?? 0;

                $path = is_string($pathVal) ? trim($pathVal) : '';
                $method = is_string($methodVal) ? strtoupper(trim($methodVal)) : 'ALL';
                $limit = is_numeric($limitVal) ? (int) $limitVal : 0;
                $window = is_numeric($windowVal) ? (int) $windowVal : 0;

                if ($path === '' || $limit <= 0 || $window <= 0) {
                    continue; // Skip invalid rules
                }

                if (!in_array($method, ['ALL', 'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], true)) {
                    $method = 'ALL';
                }

                $cleanRules[] = [
                    'path'   => $path,
                    'method' => $method,
                    'limit'  => $limit,
                    'window' => $window
                ];
            }
        }

        $rulesJson = json_encode($cleanRules);
        if (is_string($rulesJson)) {
            $settings->set('general', 'rate_limit_rules', $rulesJson);
        }

        // Audit log entry
        $audit = $this->c->get(\OwnPay\Service\System\AuditService::class);
        if ($audit instanceof \OwnPay\Service\System\AuditService) {
            $audit->log('rate_limit.settings.saved', 'settings', null, null, [
                'rules_count' => count($cleanRules)
            ]);
        }

        $this->session->flashSuccess("Rate limiting settings updated successfully.");
        return Response::redirect('/admin/developer#rate-limits');
    }

    /**
     * Web endpoint for emergency lockout bypass using signed URLs.
     */
    public function emergencyReset(Request $req): Response
    {
        $ipVal = $req->query('ip', '');
        $ip = is_string($ipVal) ? trim($ipVal) : '';
        $expiresVal = $req->query('expires', '');
        $expires = is_numeric($expiresVal) ? (int) $expiresVal : 0;
        $signatureVal = $req->query('signature', '');
        $signature = is_string($signatureVal) ? trim($signatureVal) : '';

        if ($ip === '' || $expires === 0 || $signature === '') {
            return Response::html("<h1>Forbidden</h1><p>Missing signature parameters.</p>", 403);
        }

        // Validate Expiration
        if (time() > $expires) {
            return Response::html("<h1>Forbidden</h1><p>Emergency reset signature has expired.</p>", 403);
        }

        // Validate Signature
        $payload = 'ip=' . $ip . '&expires=' . $expires;
        $appKeyRaw = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
        $appKey = is_string($appKeyRaw) ? $appKeyRaw : '';
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not configured on the server.');
        }

        $expectedSignature = hash_hmac('sha256', $payload, $appKey);
        if (!hash_equals($expectedSignature, $signature)) {
            return Response::html("<h1>Forbidden</h1><p>Invalid cryptographic signature.</p>", 403);
        }

        // Success: Clear Rate Limit
        $rateLimitRepo = $this->c->get(\OwnPay\Repository\RateLimitRepository::class);
        if ($rateLimitRepo instanceof \OwnPay\Repository\RateLimitRepository) {
            $db = $rateLimitRepo->getDatabase();
            $pattern = 'rl:' . $ip . ':%';
            $db->execute("DELETE FROM op_rate_limits WHERE key_name LIKE :pattern", ['pattern' => $pattern]);

            // Flush Redis keys for this IP
            if ($this->c->has(\OwnPay\Cache\RedisCache::class)) {
                try {
                    $cache = $this->c->get(\OwnPay\Cache\RedisCache::class);
                    if ($cache instanceof \OwnPay\Cache\RedisCache) {
                        $redis = $cache->redis();
                        $cursor = null;
                        $redisPattern = 'op:rl:' . $ip . ':*';
                        do {
                            $result = $redis->scan($cursor, $redisPattern, 100);
                            if ($result !== false && count($result) > 0) {
                                $redis->del(...$result);
                            }
                        } while ($cursor > 0);
                    }
                } catch (\Throwable) {
                    // ignore Redis failure
                }
            }

            // Log Audit event
            $audit = $this->c->get(\OwnPay\Service\System\AuditService::class);
            if ($audit instanceof \OwnPay\Service\System\AuditService) {
                $audit->log('rate_limit.emergency_reset', 'rate_limit', null, null, [
                    'ip' => $ip
                ]);
            }
        }

        // Resolve admin login slug
        $loginSlug = 'login';
        $settings = $this->c->get(SettingsRepository::class);
        if ($settings instanceof SettingsRepository) {
            $slug = $settings->get('landing', 'admin_login_slug', 'login');
            if (is_string($slug) && $slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug)) {
                $loginSlug = $slug;
            }
        }

        $this->session->flashSuccess("Rate limits for IP '{$ip}' cleared successfully. You can now log in.");
        return Response::redirect('/' . $loginSlug);
    }
}
