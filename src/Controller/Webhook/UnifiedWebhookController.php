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
 * Class UnifiedWebhookController
 *
 * Unified webhook controller - single dynamic endpoint for ALL gateways.
 * Route: POST /webhook/{gateway}
 *
 * Flow:
 *   1. Plugin hook (webhook.incoming.{gateway}) - if any listener exists
 *   2. Core fallback - GatewayApiService::handleCallback() verifies + completes
 *
 * OWASP: No user input trust. Raw body preserved for HMAC verification.
 * PCI: Never logs card data. Logs event type + payload hash only.
 *
 * @package OwnPay\Controller\Webhook
 */
final class UnifiedWebhookController
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var EventManager The event manager.
     */
    private EventManager $events;

    /**
     * UnifiedWebhookController constructor.
     *
     * @param Container    $c      The DI container.
     * @param EventManager $events The event manager.
     */
    public function __construct(Container $c, EventManager $events)
    {
        $this->c = $c;
        $this->events = $events;
    }

    /**
     * Handles incoming webhooks and IPNs for any gateway.
     *
     * @param Request $req The incoming HTTP request. Must contain route param 'gateway'.
     * @return Response The HTTP response indicating processing status.
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
        $merchantIdVal = $req->getAttribute('merchant_id') ?? $this->resolveMerchantFromPayload($req);
        $merchantId = is_numeric($merchantIdVal) ? (int) $merchantIdVal : 0;

        if ($merchantId <= 0) {
            $this->logAttempt($gateway, 'no_merchant_resolved', $req);
            return Response::json(['error' => 'Could not resolve merchant'], 400);
        }

        // SEC-6 / API-6: Fail closed on signature verification. The previous
        // implementation wrapped the signature check in
        //   if ($this->c->has(GatewayBridge::class)) { ... if ($bridge instanceof ...) { ... } }
        // which silently skipped verification when the bridge was missing or
        // misregistered - a single configuration error disabled webhook
        // authentication entirely. We now require the bridge to be resolvable
        // and to actually be a GatewayBridge instance; anything else is a
        // deployment/configuration failure that must not be allowed to fail
        // open.
        if (!$this->c->has(\OwnPay\Gateway\GatewayBridge::class)) {
            $this->logAttempt($gateway, 'signature_bridge_missing', $req);
            $this->logConfigError("GatewayBridge not registered in container; rejecting webhook for gateway={$gateway}");
            return Response::json(['error' => 'Webhook signature verification unavailable'], 500);
        }
        $bridge = $this->c->get(\OwnPay\Gateway\GatewayBridge::class);
        if (!$bridge instanceof \OwnPay\Gateway\GatewayBridge) {
            $this->logAttempt($gateway, 'signature_bridge_invalid_type', $req);
            $this->logConfigError("GatewayBridge resolved to a non-GatewayBridge instance; rejecting webhook for gateway={$gateway}");
            return Response::json(['error' => 'Webhook signature verification unavailable'], 500);
        }
        try {
            if (!$bridge->verifyWebhookSignature($gateway, $merchantId, $rawBody, $req->allHeaders())) {
                $this->logAttempt($gateway, 'signature_verification_failed', $req);
                return Response::json(['error' => 'Webhook signature verification failed'], 403);
            }
        } catch (\Throwable $e) {
            $this->logAttempt($gateway, 'signature_verification_error', $req, ['error' => $e->getMessage()]);
            return Response::json(['error' => 'Webhook signature verification error'], 403);
        }

        $hookName = "webhook.incoming.{$gateway}";

        // 1. Plugin hook - if a plugin registered a listener, let it handle
        if ($this->events->hasHook($hookName)) {
            // Guard: a plugin's own handleWebhook() typically completes the referenced
            // transaction directly (TransactionService::complete()) with no gateway-match check
            // of its own - reject here, before the plugin ever runs, if we can positively
            // identify that this transaction has already moved on to a different gateway. This
            // mirrors the same guard the core fallback path (below) already enforces, applied at
            // the one shared dispatch point instead of in every individual gateway module.
            if ($this->c->has(GatewayApiService::class)) {
                $trxRef = $this->extractTrxRef($rawBody);
                $svc = $this->c->get(GatewayApiService::class);
                if ($svc instanceof GatewayApiService
                    && !$svc->isTransactionEligibleForWebhookCompletion($trxRef, $merchantId, $gateway)
                ) {
                    $this->logAttempt($gateway, 'stale_gateway_mismatch', $req);
                    return Response::json(['error' => 'Transaction is no longer associated with this gateway'], 409);
                }
            }

            $payload = new WebhookPayload(
                gateway: $gateway,
                merchantId: $merchantId,
                rawBody: $rawBody,
                headers: $req->allHeaders(),
                ip: $req->ip(),
                method: $req->method(),
            );

            $this->events->doAction($hookName, $payload);
            $this->logDelivery($gateway, $merchantId, $payload->bodyHash());
            return Response::json(['received' => true]);
        }

        // 2. Core fallback - use GatewayApiService to verify + complete transaction. This handles the common case where gateway plugins implement GatewayAdapterInterface but don't register custom webhook hooks.
        if ($this->c->has(GatewayApiService::class)) {
            $rawWebhookBody = $rawBody;
            $callbackData = json_decode($rawWebhookBody, true);
            if (!is_array($callbackData)) {
                parse_str($rawWebhookBody, $callbackData);
            }

            $queryParams = $req->query();
            if (is_array($queryParams)) {
                $callbackData = array_merge($queryParams, $callbackData);
            }

            try {
                $svc = $this->c->get(GatewayApiService::class);
                if ($svc instanceof GatewayApiService) {
                    $result = $svc->handleCallback($merchantId, $gateway, $callbackData, true);

                    $payloadHash = hash('sha256', $rawBody);
                    $this->logDelivery($gateway, $merchantId, $payloadHash);

                    if ($result['success'] === true) {
                        return Response::json(['received' => true, 'status' => 'completed']);
                    }

                    $reason = isset($result['error']) ? $result['error'] : 'verification_failed';
                    return Response::json([
                        'received' => true,
                        'status'   => 'unprocessed',
                        'reason'   => $reason,
                    ]);
                }
            } catch (\Throwable $e) {
                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->error(
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
     * Extracts every candidate transaction/session reference from a webhook body.
     *
     * Checks two shapes:
     * - Flat top-level fields, used by most gateways (bKash, SSLCommerz-style IPNs, etc.):
     *   trx_id, tran_id, order_id, reference, merchant_order_id, invoice_id, client_reference_id.
     * - Stripe's nested event shape, where nothing relevant is ever at the top level - everything
     *   lives under `data.object` (docs.stripe.com/api/events/object). StripeGateway::initiate()
     *   sets `metadata[trx_id]` on the Checkout Session, so `data.object.metadata.trx_id` is the
     *   reliable match; `data.object.client_reference_id` and `data.object.id` (the session/
     *   payment-intent id, matching our stored gateway_trx_id/gateway_session_id) are included as
     *   fallbacks for events where metadata isn't echoed back the same way.
     *
     * Without the nested lookup, every Stripe webhook fails merchant resolution and signature
     * verification never even runs - Stripe's payload structurally never matches the flat-only list.
     *
     * @param array<int|string, mixed> $data Decoded webhook body (JSON-decoded, or parse_str() fallback).
     * @return array<int, string> Ordered list of non-empty candidate reference strings to try.
     */
    private function extractReferenceCandidates(array $data): array
    {
        $candidates = [];

        $flatFields = ['trx_id', 'tran_id', 'order_id', 'reference', 'merchant_order_id', 'invoice_id', 'client_reference_id'];
        foreach ($flatFields as $field) {
            $val = $data[$field] ?? null;
            if (is_scalar($val) && (string) $val !== '') {
                $candidates[] = (string) $val;
            }
        }

        $object = $data['data'] ?? null;
        $object = is_array($object) ? ($object['object'] ?? null) : null;
        if (is_array($object)) {
            $metadata = $object['metadata'] ?? null;
            $metaTrxId = is_array($metadata) ? ($metadata['trx_id'] ?? null) : null;
            if (is_scalar($metaTrxId) && (string) $metaTrxId !== '') {
                $candidates[] = (string) $metaTrxId;
            }
            $objClientRef = $object['client_reference_id'] ?? null;
            if (is_scalar($objClientRef) && (string) $objClientRef !== '') {
                $candidates[] = (string) $objClientRef;
            }
            $objId = $object['id'] ?? null;
            if (is_scalar($objId) && (string) $objId !== '') {
                $candidates[] = (string) $objId;
            }
            // Stripe charge/dispute/refund events nest the PaymentIntent id one level down
            // instead of at object.id (e.g. charge.updated -> data.object.payment_intent).
            // We store the PaymentIntent id as gateway_trx_id, so this is often the only match.
            $objPaymentIntent = $object['payment_intent'] ?? null;
            if (is_scalar($objPaymentIntent) && (string) $objPaymentIntent !== '') {
                $candidates[] = (string) $objPaymentIntent;
            }
        }

        return $candidates;
    }

    /**
     * Decodes a raw webhook body (JSON, falling back to form-encoded) into an array.
     *
     * @param string $rawBody The raw request body.
     * @return array<int|string, mixed> Decoded payload, or [] if neither format parses.
     */
    private function decodeWebhookBody(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (is_array($data)) {
            return $data;
        }
        parse_str($rawBody, $data);
        return $data;
    }

    /**
     * Extracts a transaction reference from the raw webhook body.
     *
     * @param string $rawBody The raw (JSON or form-encoded) webhook request body.
     * @return string The first recognized reference value found, or '' if none.
     */
    private function extractTrxRef(string $rawBody): string
    {
        $candidates = $this->extractReferenceCandidates($this->decodeWebhookBody($rawBody));
        return $candidates[0] ?? '';
    }

    /**
     * Fallback: resolves the merchant ID from a transaction reference in the webhook payload.
     *
     * @param Request $req The incoming HTTP request.
     * @return int The resolved merchant/brand ID, or 0 if unable to resolve.
     */
    private function resolveMerchantFromPayload(Request $req): int
    {
        $body = $req->rawBody() ?? '';
        $data = $this->decodeWebhookBody($body);
        $candidates = $this->extractReferenceCandidates($data);

        if ($candidates === []) {
            return 0;
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return 0;
        }

        foreach ($candidates as $refStr) {
            // Correct column name is 'trx_id', not 'transaction_id'
            $txn = $db->fetchOne(
                "SELECT merchant_id FROM op_transactions WHERE trx_id = :ref OR gateway_trx_id = :ref2 LIMIT 1",
                ['ref' => $refStr, 'ref2' => $refStr]
            );
            if (is_array($txn) && isset($txn['merchant_id']) && is_numeric($txn['merchant_id'])) {
                return (int) $txn['merchant_id'];
            }
        }

        return 0; // Unknown merchant - core handler will reject with 400
    }

    /**
     * Logs rejected webhook attempts (no-listener or invalid slug).
     *
     * @param string  $gateway The gateway identifier.
     * @param string  $reason  The rejection reason.
     * @param Request $req     The incoming HTTP request.
     * @param array<string, mixed> $context  Additional logging context.
     * @return void
     */
    private function logAttempt(string $gateway, string $reason, Request $req, array $context = []): void
    {
        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
        if ($logger instanceof \OwnPay\Service\System\Logger) {
            // Strip control characters/newlines from reason to prevent log forging
            $sanitizedReason = preg_replace('/[\r\n\t]+/', ' ', $reason);
            $sanitizedReasonStr = is_string($sanitizedReason) ? $sanitizedReason : $reason;
            $logger->warning(
                "Webhook rejected: gateway={$gateway} reason={$sanitizedReasonStr} ip={$req->ip()}",
                $context
            );
        }
    }

    /**
     * Logs a configuration-level error that prevented webhook signature verification.
     *
     * Unlike {@see logAttempt}, this is for deployment/config faults (e.g. the
     * GatewayBridge service is missing from the container) rather than
     * per-request rejections. The message is sanitized for log forging.
     *
     * @param string $message The configuration error description.
     * @return void
     */
    private function logConfigError(string $message): void
    {
        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
        if ($logger instanceof \OwnPay\Service\System\Logger) {
            $sanitized = preg_replace('/[\r\n\t]+/', ' ', $message);
            $sanitizedStr = is_string($sanitized) ? $sanitized : $message;
            $logger->error('Webhook signature config error: ' . $sanitizedStr);
        }
    }

    /**
     * Logs a successful webhook delivery to the database.
     * PCI DSS: Stores only a SHA-256 hash of the payload, never card details.
     *
     * @param string $gateway     The gateway identifier.
     * @param int    $merchantId  The brand ID.
     * @param string $payloadHash The hash of the request body.
     * @return void
     */
    private function logDelivery(string $gateway, int $merchantId, string $payloadHash): void
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if ($db instanceof \OwnPay\Core\Database) {
            $db->insert(
                "INSERT INTO op_webhook_deliveries (merchant_id, gateway, direction, status, payload_hash, created_at) VALUES (:mid, :gw, 'inbound', 'received', :hash, NOW(6))",
                ['mid' => $merchantId ?: null, 'gw' => $gateway, 'hash' => $payloadHash]
            );
        }
    }
}
