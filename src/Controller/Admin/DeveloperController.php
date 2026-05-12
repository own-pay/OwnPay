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
 * Developer Hub — API keys, endpoint reference, webhook config, documentation.
 */
final class DeveloperController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;

    public function __construct(Container $c, AdminSession $session)
    {
        $this->c       = $c;
        $this->session = $session;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $apiKeys  = $this->c->get(ApiKeyService::class)->list($mid);
        $settings = $this->c->get(SettingsRepository::class);
        $webhookUrl    = $settings->get('general', 'webhook_url', '');
        $webhookSecret = $settings->get('general', 'webhook_secret', '');
        $apiRateLimit  = $settings->get('general', 'api_rate_limit', '60');
        $baseUrl       = $settings->get('general', 'base_url', '');
        if (empty($baseUrl)) {
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        // All public API endpoints for reference
        $endpoints = $this->getEndpointReference($baseUrl);

        return $this->renderAdminPage('admin/developer/index.twig', [
            'api_keys'       => $apiKeys,
            'webhook_url'    => $webhookUrl,
            'webhook_secret' => $webhookSecret,
            'api_rate_limit' => $apiRateLimit,
            'base_url'       => rtrim($baseUrl, '/'),
            'endpoints'      => $endpoints,
            'active_page'    => 'developer',
        ]);
    }

    public function webhookTest(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        $brand->resolveFromRequest($req);

        $settings   = $this->c->get(SettingsRepository::class);
        $webhookUrl = $settings->get('general', 'webhook_url', '');

        if (empty($webhookUrl)) {
            return Response::json(['success' => false, 'error' => 'No webhook URL configured']);
        }

        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return Response::json(['success' => false, 'error' => 'Invalid webhook URL']);
        }

        $payload = json_encode([
            'event'     => 'webhook.test',
            'timestamp' => time(),
            'data'      => ['message' => 'OwnPay webhook test event'],
        ]);

        $secret = $settings->get('general', 'webhook_secret', '');
        $sig    = hash_hmac('sha256', (string) $payload, $secret);

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

    /** @return array<string, list<array{method:string, path:string, auth:string, desc:string}>> */
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

    /** POST /admin/developer/save-limits — Save rate limit + webhook settings */
    public function saveLimits(Request $req): Response
    {
        $settings = $this->c->get(SettingsRepository::class);
        $rateLimit    = (int) $req->post('api_rate_limit', 60);
        $webhookUrl   = trim($req->post('webhook_url', ''));
        $webhookSecret = trim($req->post('webhook_secret', ''));

        if ($rateLimit < 1 || $rateLimit > 10000) {
            $this->session->flashError('Rate limit must be 1–10000 requests/min');
            return Response::redirect('/admin/developer');
        }
        if ($webhookUrl && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
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

    /** POST /admin/developer/generate-key — Generate new API key */
    public function generateKey(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $label = trim($req->post('label', 'API Key'));
        $keyService = $this->c->get(ApiKeyService::class);
        $result = $keyService->generate($mid, $label);

        if (!empty($result['key'])) {
            $this->session->flashSuccess("API key created: {$result['key']}");
        } else {
            $this->session->flashError($result['error'] ?? 'Key generation failed');
        }

        return Response::redirect('/admin/developer');
    }
}
