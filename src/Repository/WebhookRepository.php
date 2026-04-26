<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\UuidGenerator;

/**
 * Repository for webhook system:
 *   - op_webhooks (endpoint config)
 *   - op_webhook_events (inbound event dedupe) — PARTITIONED
 *   - op_webhook_delivery_logs (outbound delivery tracking)
 */
class WebhookRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_webhooks';

    // ─── Webhook Endpoints ───────────────────────────────────────────

    /**
     * Find active webhook endpoints for a merchant and event type.
     */
    public function findEndpoints(int $merchantId, string $eventType): array
    {
        $tc = $this->tenantCondition();
        // JSON_CONTAINS checks if the event_type is in the events JSON array
        return $this->db->fetchAll(
            "SELECT * FROM `op_webhooks`
             WHERE `merchant_id` = :mid
               AND `status` = 'active'
               AND JSON_CONTAINS(`events`, :evt){$tc}",
            array_merge([
                'mid' => $merchantId,
                'evt' => json_encode($eventType),
            ], $this->tenantParams())
        );
    }

    // ─── Webhook Events (Dedupe) ─────────────────────────────────────

    /**
     * Check if an event has already been processed (dedupe).
     */
    public function eventExists(string $eventId): bool
    {
        $tc = $this->tenantCondition();
        $row = $this->db->fetchOne(
            "SELECT `id` FROM `op_webhook_events`
             WHERE `event_id` = :eid{$tc} LIMIT 1",
            array_merge(['eid' => $eventId], $this->tenantParams())
        );
        return $row !== null;
    }

    /**
     * Record a new webhook event.
     */
    public function createEvent(
        int $merchantId,
        string $eventType,
        string $payload,
        string $sourceIp = ''
    ): int {
        $eventId = UuidGenerator::generate();
        $now = gmdate('Y-m-d H:i:s.u');

        $this->db->execute(
            "INSERT INTO `op_webhook_events`
             (`event_id`, `merchant_id`, `event_type`, `payload`,
              `source_ip`, `status`, `created_at`)
             VALUES (:eid, :mid, :et, :pl, :ip, 'received', :ca)",
            [
                'eid' => $eventId,
                'mid' => $merchantId,
                'et' => $eventType,
                'pl' => $payload,
                'ip' => $sourceIp,
                'ca' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // ─── Delivery Logs ───────────────────────────────────────────────

    /**
     * Log a webhook delivery attempt.
     */
    public function logDelivery(
        int $webhookId,
        int $webhookEventId,
        string $url,
        string $requestHeaders,
        string $requestBody,
        int $httpStatus,
        string $responseBody,
        int $attemptNumber,
        bool $success
    ): int {
        $publicId = UuidGenerator::generate();
        $now = gmdate('Y-m-d H:i:s.u');

        $this->db->execute(
            "INSERT INTO `op_webhook_delivery_logs`
             (`public_id`, `webhook_id`, `webhook_event_id`, `url`,
              `request_headers`, `request_body`, `http_status`,
              `response_body`, `attempt_number`, `success`,
              `created_at`, `updated_at`)
             VALUES (:pid, :wid, :weid, :url, :rh, :rb, :hs,
                     :resp, :an, :succ, :ca, :ua)",
            [
                'pid' => $publicId,
                'wid' => $webhookId,
                'weid' => $webhookEventId,
                'url' => $url,
                'rh' => $requestHeaders,
                'rb' => $requestBody,
                'hs' => $httpStatus,
                'resp' => $responseBody,
                'an' => $attemptNumber,
                'succ' => $success ? 1 : 0,
                'ca' => $now,
                'ua' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }
}
