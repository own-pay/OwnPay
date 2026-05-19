<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;

/**
 * Payment link service — CRUD for merchant payment links.
 */
final class PaymentLinkService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function listForMerchant(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM op_payment_links WHERE merchant_id = :mid ORDER BY created_at DESC",
            ['mid' => $merchantId]
        );
    }

    public function find(int $merchantId, int $id): ?array
    {
        $link = $this->db->fetchOne(
            "SELECT * FROM op_payment_links WHERE id = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $merchantId]
        );
        if ($link === null) {
            return null;
        }
        $link['fields'] = $this->db->fetchAll(
            "SELECT * FROM op_payment_link_fields WHERE payment_link_id = :id ORDER BY sort_order",
            ['id' => $id]
        );
        return $link;
    }

    public function create(int $merchantId, array $data): array
    {
        $slug = $data['slug'] ?? (preg_replace('/[^a-z0-9\-]+/', '', str_replace(' ', '-', strtolower($data['title'] ?? 'link'))) . '-' . substr(uniqid(), -4));
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $id = $this->db->insert(
            "INSERT INTO op_payment_links (merchant_id, uuid, slug, title, description, amount, currency, is_amount_fixed, min_amount, max_amount, redirect_url, max_uses, expires_at, status, created_at)
             VALUES (:mid, :uuid, :slug, :title, :desc, :amount, :cur, :fixed, :min, :max, :redir, :uses, :exp, 'active', NOW())",
            [
                'mid'   => $merchantId,
                'uuid'  => $uuid,
                'slug'  => $slug,
                'title' => $data['title'] ?? '',
                'desc'  => !empty($data['description']) ? $data['description'] : null,
                'amount' => !empty($data['amount']) ? $data['amount'] : null,
                'cur'   => $data['currency'] ?? 'BDT',
                'fixed' => isset($data['is_amount_fixed']) ? 1 : 0,
                'min'   => !empty($data['min_amount']) ? $data['min_amount'] : null,
                'max'   => !empty($data['max_amount']) ? $data['max_amount'] : null,
                'redir' => !empty($data['redirect_url']) ? $data['redirect_url'] : null,
                'uses'  => !empty($data['max_uses']) ? (int) $data['max_uses'] : null,
                'exp'   => !empty($data['expires_at']) ? $data['expires_at'] : null,
            ]
        );

        return $this->find($merchantId, (int) $id) ?? [];
    }

    public function update(int $merchantId, int $id, array $data): array
    {
        $this->db->update(
            "UPDATE op_payment_links SET title = :title, description = :desc, amount = :amount, currency = :cur, status = :st, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
            [
                'title'  => $data['title'] ?? '',
                'desc'   => $data['description'] ?? null,
                'amount' => $data['amount'] ?? null,
                'cur'    => $data['currency'] ?? 'BDT',
                'st'     => $data['status'] ?? 'active',
                'id'     => $id,
                'mid'    => $merchantId,
            ]
        );
        return $this->find($merchantId, $id) ?? [];
    }

    /**
     * Ensure a brand has at least one default payment link.
     * Called on brand creation. Idempotent — skips if links already exist.
     */
    public function ensureDefault(int $merchantId, string $brandName, string $brandSlug, string $currency = 'BDT'): void
    {
        $existing = $this->listForMerchant($merchantId);
        if (!empty($existing)) {
            return;
        }

        $this->create($merchantId, [
            'title'       => $brandName . ' Payment',
            'slug'        => $brandSlug . '-pay',
            'description' => 'Default payment link for ' . $brandName,
            'currency'    => $currency,
            // amount left null = customer enters custom amount
        ]);
    }

    /**
     * Find payment link by slug (public checkout).
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM op_payment_links WHERE slug = :slug AND status = 'active'",
            ['slug' => $slug]
        );
    }
}
