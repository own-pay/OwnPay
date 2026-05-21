<?php
declare(strict_types=1);

namespace OwnPay\Controller\Webhook;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Model\WebhookPayload;
use OwnPay\Service\Payment\GatewayApiService;

/**
 * Unified webhook controller - single dynamic endpoint for ALL gateways.
 *
 * Route: POST /webhook/{gateway}
 *
 * Flow:
 *   1. Plugin hook (webhook.incoming.{gateway}) — if any listener exists
 *   2. Core fallback — GatewayApiService::handleCallback() verifies + completes
 *
 * OWASP: No user input trust. Raw body preserved for HMAC verification.
 * PCI: Never logs card data. Logs event type + payload hash only.
 */
final class UnifiedWebhookController
{
    private Container $c;
    private EventManager $events;

    public function __construct(Container $c, EventManager $events)
    {
        $this->c = $c;
        $this->events = $events;
    }

    /**
     * Handle incoming webhook/IPN for any gateway.
     *
     * @param Request $req Must contain route param 'gateway'
     */
    public function handle(Request $req): Response
    {
        // Reject oversized webhook payloads (max 1MB).
        // Prevents memory exhaustion / DoS. Generous for any gateway callback.
        $maxBodySize = 1_048_576; // 1MB
        $rawBody = $req->rawBody() ?? '';
        if (strlen($rawBody) > $maxBodySize) {
            return Response::json(['error' => 'Payload too large'], 413);
        }

        $gateway = $req->param('gateway') ?? '' /** @phpstan-ignore nullCoalesce.expr */;

        // Sanitize gateway slug - alphanumeric + hyphens only
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,49}$/', $gateway)) {
            $this->logAttempt($gateway, 'invalid_slug', $req);
            return Response::json(['error' => 'Invalid gateway identifier'], 400);
        }

        // Resolve merchant ID - injected by DomainResolverMiddleware or fallback
        $merchantId = $req->getAttribute('merchant_id') ?? $this->resolveMerchantFromPayload($req);

        if ((int) $merchantId <= 0) {
            $this->logAttempt($gateway, 'no_merchant_resolved', $req);
            return Response::json(['error' => 'Could not resolve merchant'], 400);
        }

        // AUD-G6: Delegate webhook signature verification to gateway adapter before plugin or core dispatch
        if ($this->c->has(\OwnPay\Gateway\GatewayBridge::class)) {
            $bridge = $this->c->get(\OwnPay\Gateway\GatewayBridge::class);
            try {
                if (!$bridge->verifyWebhookSignature($gateway, (int) $merchantId, $rawBody, $req->allHeaders())) {
                    $this->logAttempt($gateway, 'signature_verification_failed', $req);
                    return Response::json(['error' => 'Webhook signature verification failed'], 403);
                }
            } catch (\Throwable $e) {
                $this->logAttempt($gateway, 'signature_verification_error: ' . $e->getMessage(), $req);
                return Response::json(['error' => 'Webhook signature verification error'], 403);
            }
        }

        $hookName = "webhook.incoming.{$gateway}";

        // 1. Plugin hook — if a plugin registered a listener, let it handle
        if ($this->events->hasHook($hookName)) {
            $payload = new WebhookPayload(
                gateway: $gateway,
                merchantId: (int) $merchantId,
                rawBody: $req->rawBody(),
                headers: $req->allHeaders(),
                ip: $req->ip(),
                method: $req->method(),
            );

            $this->events->doAction($hookName, $payload);
            $this->logDelivery($gateway, $merchantId, $payload->bodyHash());
            return Response::json(['received' => true]);
        }

        // 2. Core fallback — use GatewayApiService to verify + complete transaction.
        // This handles the common case where gateway plugins implement
        // GatewayAdapterInterface but don't register custom webhook hooks.
        if ($this->c->has(GatewayApiService::class)) {
            $rawWebhookBody = $req->rawBody() ?? '';
            $callbackData = json_decode($rawWebhookBody, true);
            if (!is_array($callbackData)) {
                parse_str($rawWebhookBody, $callbackData);
            }
            // Merge query params — some gateways (SSLCommerz) include data in GET params.
            // POST body takes precedence over GET to prevent parameter spoofing (AUD-A5).
            $queryParams = $req->query();
            if (is_array($queryParams)) {
                $callbackData = array_merge($queryParams, $callbackData);
            }

            try {
                $svc = $this->c->get(GatewayApiService::class);
                $result = $svc->handleCallback((int) $merchantId, $gateway, $callbackData);

                $payloadHash = hash('sha256', $req->rawBody());
                $this->logDelivery($gateway, $merchantId, $payloadHash);

                if ($result['success'] ?? false) {
                    return Response::json(['received' => true, 'status' => 'completed']);
                }

                return Response::json([
                    'received' => true,
                    'status'   => 'unprocessed',
                    'reason'   => $result['error'] ?? 'verification_failed',
                ]);
            } catch (\Throwable $e) {
                if ($this->c->has(\OwnPay\Service\System\Logger::class)) {
                    $this->c->get(\OwnPay\Service\System\Logger::class)->error(
                        "Webhook core handler failed: gateway={$gateway} error={$e->getMessage()}"
                    );
                }
                return Response::json(['error' => 'Processing failed'], 500);
            }
        }

        // 3. No handler available at all
        $this->logAttempt($gateway, 'no_handler', $req);
        return Response::json(['error' => 'Unknown gateway'], 404);
    }

    /**
     * Fallback: resolve merchant from transaction reference in payload.
     */
    private function resolveMerchantFromPayload(Request $req): int
    {
        $body = $req->rawBody() ?? '';
        $data = json_decode($body, true);

        if (!is_array($data)) {
            parse_str($body, $data);
        }

        // Common field names for transaction references across gateways
        $refFields = ['order_id', 'tran_id', 'invoice_id', 'reference', 'merchant_order_id', 'client_reference_id'];

        foreach ($refFields as $field) {
            if (!empty($data[$field])) {
                $ref = $data[$field];
                $db = $this->c->get(\OwnPay\Core\Database::class);
                // Correct column name is 'trx_id', not 'transaction_id'
                $txn = $db->fetchOne(
                    "SELECT merchant_id FROM op_transactions WHERE trx_id = :ref OR gateway_trx_id = :ref2 LIMIT 1",
                    ['ref' => $ref, 'ref2' => $ref]
                );
                if ($txn && !empty($txn['merchant_id'])) {
                    return (int) $txn['merchant_id'];
                }
            }
        }

        return 0; // Unknown merchant - core handler will reject with 400
    }

    /**
     * Log webhook attempt (no-listener / invalid slug).
     */
    private function logAttempt(string $gateway, string $reason, Request $req): void
    {
        if ($this->c->has(\OwnPay\Service\System\Logger::class)) {
            $this->c->get(\OwnPay\Service\System\Logger::class)->warning(
                "Webhook rejected: gateway={$gateway} reason={$reason} ip={$req->ip()}"
            );
        }
    }

    /**
     * Log successful webhook delivery. PCI: hash only, no raw payload.
     */
    private function logDelivery(string $gateway, int $merchantId, string $payloadHash): void
    {
        if ($this->c->has(\OwnPay\Core\Database::class)) {
            $db = $this->c->get(\OwnPay\Core\Database::class);
            $db->insert(
                "INSERT INTO op_webhook_deliveries (merchant_id, gateway, direction, status, payload_hash, created_at) VALUES (:mid, :gw, 'inbound', 'received', :hash, NOW(6))",
                ['mid' => $merchantId ?: null, 'gw' => $gateway, 'hash' => $payloadHash]
            );
        }
    }
}
