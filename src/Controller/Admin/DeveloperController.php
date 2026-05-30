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
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

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
        $baseUrlVal       = $settings->get('general', 'base_url', '');
        $baseUrl          = is_string($baseUrlVal) ? $baseUrlVal : '';

        if (empty($baseUrl)) {
            $baseUrl = ($req->isSecure() ? 'https' : 'http') . '://' . ($req->header('Host') ?: 'localhost');
        }

        // All public API endpoints for reference
        $endpoints = $this->getEndpointReference($baseUrl);

        // Consume one-time generated API key from session (shown once, then gone)
        $generatedKey = $_SESSION['_generated_api_key'] ?? null;
        $generatedKeyLabel = $_SESSION['_generated_api_key_label'] ?? '';
        unset($_SESSION['_generated_api_key'], $_SESSION['_generated_api_key_label']);

        return $this->renderAdminPage('admin/developer/index.twig', [
            'api_keys'            => $apiKeys,
            'webhook_url'         => $webhookUrl,
            'webhook_secret'      => $webhookSecret,
            'api_rate_limit'      => $apiRateLimit,
            'base_url'            => rtrim($baseUrl, '/'),
            'endpoints'           => $endpoints,
            'active_page'         => 'developer',
            'generated_key'       => $generatedKey,
            'generated_key_label' => $generatedKeyLabel,
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

        if (!\OwnPay\Security\UrlValidator::isValidWebhookUrl($webhookUrl)) {
            return Response::json(['success' => false, 'error' => 'Invalid webhook URL']);
        }

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
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-OwnPay-Signature: sha256=' . $sig,
                'X-OwnPay-Event: webhook.test',
            ],
        ]);
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
                ['method' => 'GET',  'path' => '/api/v1/health',                  'auth' => 'None',   'desc' => 'Health check — returns system status, version, and DB connectivity'],
            ],
            'Payments' => [
                ['method' => 'POST', 'path' => '/api/v1/payments/initiate',        'auth' => 'Bearer', 'desc' => 'Initiate a new payment intent. Returns checkout_url and payment_id'],
                ['method' => 'GET',  'path' => '/api/v1/payments/{id}',            'auth' => 'Bearer', 'desc' => 'Get payment intent status by ID'],
            ],
            'Transactions' => [
                ['method' => 'GET',  'path' => '/api/v1/transactions',             'auth' => 'Bearer', 'desc' => 'List transactions (paginated). Supports ?page=1&per_page=20&status=completed'],
                ['method' => 'GET',  'path' => '/api/v1/transactions/{id}',        'auth' => 'Bearer', 'desc' => 'Get single transaction detail'],
            ],
            'Refunds' => [
                ['method' => 'POST', 'path' => '/api/v1/refunds',                  'auth' => 'Bearer', 'desc' => 'Create a refund for a completed transaction'],
                ['method' => 'GET',  'path' => '/api/v1/refunds/{id}',             'auth' => 'Bearer', 'desc' => 'Get refund status'],
            ],
            'Customers' => [
                ['method' => 'GET',  'path' => '/api/v1/customers',                'auth' => 'Bearer', 'desc' => 'List customers (paginated)'],
                ['method' => 'POST', 'path' => '/api/v1/customers',                'auth' => 'Bearer', 'desc' => 'Create a new customer record'],
                ['method' => 'GET',  'path' => '/api/v1/customers/{id}',           'auth' => 'Bearer', 'desc' => 'Get customer details'],
            ],
            'API Keys' => [
                ['method' => 'GET',  'path' => '/api/v1/api-keys',                 'auth' => 'Bearer', 'desc' => 'List API keys for current brand'],
                ['method' => 'POST', 'path' => '/api/v1/api-keys',                 'auth' => 'Bearer', 'desc' => 'Generate a new API key'],
                ['method' => 'POST', 'path' => '/api/v1/api-keys/{id}/revoke',     'auth' => 'Bearer', 'desc' => 'Revoke an API key immediately'],
            ],
            'Webhooks' => [
                ['method' => 'POST', 'path' => '/api/v1/webhooks/test',            'auth' => 'Bearer', 'desc' => 'Send a test event to your webhook URL'],
                ['method' => 'GET',  'path' => '/api/v1/webhooks/deliveries',      'auth' => 'Bearer', 'desc' => 'List recent webhook delivery attempts with status'],
            ],
            'Mobile Device API' => [
                ['method' => 'POST', 'path' => '/api/mobile/v1/devices/pair',      'auth' => 'OTP',    'desc' => 'Pair mobile device using 6-digit OTP from admin panel'],
                ['method' => 'POST', 'path' => '/api/mobile/v1/devices/heartbeat', 'auth' => 'JWT',    'desc' => 'Update device last-seen timestamp'],
                ['method' => 'POST', 'path' => '/api/mobile/v1/sms',              'auth' => 'JWT',    'desc' => 'Submit encrypted SMS batch (max 20) for server-side parsing'],
                ['method' => 'GET',  'path' => '/api/mobile/v1/notifications',     'auth' => 'JWT',    'desc' => 'Poll for unread payment notifications'],
                ['method' => 'POST', 'path' => '/api/mobile/v1/notifications/ack', 'auth' => 'JWT',    'desc' => 'Acknowledge / mark notifications as read'],
                ['method' => 'GET',  'path' => '/api/mobile/v1/dashboard',         'auth' => 'JWT',    'desc' => 'Mobile dashboard summary — today totals, recent transactions'],
            ],
            'Webhooks (Incoming / IPN)' => [
                ['method' => 'POST', 'path' => '/webhook/{gateway}',               'auth' => 'Sig',    'desc' => 'Unified IPN endpoint for gateway callbacks. Replace {gateway} with gateway slug (e.g. bkash-api, stripe)'],
            ],
        ];
    }

    /**
     * Saves general API configurations, rate limits, and webhook settings.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function saveLimits(Request $req): Response
    {
        $settings = $this->c->get(SettingsRepository::class);
        if (!$settings instanceof SettingsRepository) {
            throw new \RuntimeException('SettingsRepository service unavailable');
        }
        $rateLimitVal = $req->post('api_rate_limit', 60);
        $rateLimit    = is_int($rateLimitVal) || is_string($rateLimitVal) ? (int)$rateLimitVal : 60;
        $webhookUrlVal   = $req->post('webhook_url', '');
        $webhookUrl      = is_string($webhookUrlVal) ? trim($webhookUrlVal) : '';
        $webhookSecretVal = $req->post('webhook_secret', '');
        $webhookSecret   = is_string($webhookSecretVal) ? trim($webhookSecretVal) : '';

        if ($rateLimit < 1 || $rateLimit > 10000) {
            $this->session->flashError('Rate limit must be 1–10000 requests/min');
            return Response::redirect('/admin/developer');
        }
        if ($webhookUrl !== '' && !\OwnPay\Security\UrlValidator::isValidWebhookUrl($webhookUrl)) {
            $this->session->flashError('Invalid webhook URL');
            return Response::redirect('/admin/developer');
        }

        $settings->set('general', 'api_rate_limit', (string) $rateLimit);
        if ($webhookUrl !== '') {
            $settings->set('general', 'webhook_url', $webhookUrl);
        }
        if ($webhookSecret !== '') {
            $settings->set('general', 'webhook_secret', $webhookSecret);
        }

        $this->session->flashSuccess('Developer settings saved');
        return Response::redirect('/admin/developer');
    }

    /**
     * Generates a new API access key.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function generateKey(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $labelVal = $req->post('label', 'API Key');
        $label = is_string($labelVal) ? trim($labelVal) : 'API Key';
        
        $scopesPost = $req->post('scopes');
        $scopes = [];
        if (is_array($scopesPost)) {
            foreach ($scopesPost as $scope) {
                if (is_string($scope) && in_array($scope, ['read', 'write', 'admin'], true)) {
                    $scopes[] = $scope;
                }
            }
        }
        if (empty($scopes)) {
            $scopes = ['read', 'write'];
        }

        $keyService = $this->c->get(ApiKeyService::class);
        if (!$keyService instanceof ApiKeyService) {
            throw new \RuntimeException('ApiKeyService service unavailable');
        }
        $result = $keyService->generate($mid, $label, $scopes);

        $_SESSION['_generated_api_key'] = $result['key'];
        $_SESSION['_generated_api_key_label'] = $label;
        $this->session->flashSuccess("API key \"{$label}\" generated successfully. Copy it below — it won't be shown again.");

        return Response::redirect('/admin/developer');
    }
}
