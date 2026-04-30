<?php
declare(strict_types=1);

namespace OwnPay\Controller\Webhook;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Model\WebhookPayload;

/**
 * Unified webhook controller — single dynamic endpoint for ALL gateways.
 *
 * Route: POST /webhook/{gateway}
 *
 * Fires: webhook.incoming.{gateway} hook → plugin handles verification + processing.
 * Zero core modification required to add new gateways.
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
        $gateway = $req->param('gateway') ?? '';

        // Sanitize gateway slug — alphanumeric + hyphens only
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,49}$/', $gateway)) {
            $this->logAttempt($gateway, 'invalid_slug', $req);
            return Response::json(['error' => 'Invalid gateway identifier'], 400);
        }

        $hookName = "webhook.incoming.{$gateway}";

        // Check if any plugin listens to this gateway's webhook hook
        if (!$this->events->hasHook($hookName)) {
            $this->logAttempt($gateway, 'no_listener', $req);
            return Response::json(['error' => 'Unknown gateway'], 404);
        }

        // Resolve merchant ID — injected by DomainResolverMiddleware or fallback
        $merchantId = $req->getAttribute('merchant_id') ?? $this->resolveMerchantFromPayload($req);

        // Build immutable payload for plugin consumption
        $payload = new WebhookPayload(
            gateway: $gateway,
            merchantId: (int) $merchantId,
            rawBody: $req->rawBody(),
            headers: $req->allHeaders(),
            ip: $req->ip(),
            method: $req->method(),
        );

        // Fire hook — plugin handles verification + processing
        $this->events->doAction($hookName, $payload);

        // Log delivery (PCI: payload hash only, never raw card data)
        $this->logDelivery($gateway, $merchantId, $payload->bodyHash());

        return Response::json(['received' => true]);
    }

    /**
     * Fallback: resolve merchant from transaction reference in payload.
     */
    private function resolveMerchantFromPayload(Request $req): int
    {
        $body = $req->rawBody();
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
                $txn = $db->fetchOne(
                    "SELECT merchant_id FROM op_transactions WHERE transaction_id = :ref OR gateway_txn_id = :ref2 LIMIT 1",
                    ['ref' => $ref, 'ref2' => $ref]
                );
                if ($txn && !empty($txn['merchant_id'])) {
                    return (int) $txn['merchant_id'];
                }
            }
        }

        return 0; // Unknown merchant — plugin must handle
    }

    /**
     * Log webhook attempt (no-listener / invalid slug).
     */
    private function logAttempt(string $gateway, string $reason, Request $req): void
    {
        if ($this->c->has(\OwnPay\Core\Logger::class)) {
            $this->c->get(\OwnPay\Core\Logger::class)->warning(
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
                "INSERT INTO op_webhook_deliveries (gateway, merchant_id, direction, payload_hash, status, created_at) VALUES (:gw, :mid, 'inbound', :hash, 'received', NOW())",
                ['gw' => $gateway, 'mid' => $merchantId, 'hash' => $payloadHash]
            );
        }
    }
}
