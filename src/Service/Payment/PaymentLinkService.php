<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;

/**
 * Service managing merchant payment links and their dynamic configurations.
 *
 * Handles creation, lookup, validation, updates, and initialization of default payment links
 * that customers use to pay custom or fixed amounts directly via unique public URLs.
 */
final class PaymentLinkService
{
    /**
     * @var Database The database abstraction service.
     */
    private Database $db;

    /**
     * PaymentLinkService constructor.
     *
     * @param Database $db The database helper instance.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Lists all payment links created by a specific merchant/brand.
     *
     * @param int $merchantId The unique ID of the merchant.
     * @return array<int, array<string, mixed>> The array of payment link records.
     */
    public function listForMerchant(?int $merchantId): array
    {
        // merchantId === null => global "All Brands" view: aggregate across all brands.
        if ($merchantId === null) {
            return $this->db->fetchAll(
                "SELECT * FROM op_payment_links ORDER BY created_at DESC"
            );
        }
        return $this->db->fetchAll(
            "SELECT * FROM op_payment_links WHERE merchant_id = :mid ORDER BY created_at DESC",
            ['mid' => $merchantId]
        );
    }

    /**
     * Finds a specific payment link by ID and scopes it to the merchant.
     *
     * Loads any dynamically configured fields associated with the payment link.
     *
     * @param int $merchantId The unique ID of the merchant.
     * @param int $id The unique ID of the payment link.
     * @return array<string, mixed>|null The payment link fields and config, or null if not found.
     */
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

    /**
     * Creates a new payment link.
     *
     * Generates a clean URL slug if one is not explicitly supplied in the parameters.
     *
     * @param int $merchantId The unique ID of the merchant.
     * @param array{
     *     title?: string,
     *     slug?: string,
     *     description?: string|null,
     *     amount?: float|int|string|null,
     *     currency?: string,
     *     is_amount_fixed?: bool|int,
     *     require_address?: bool|int,
     *     min_amount?: float|int|string|null,
     *     max_amount?: float|int|string|null,
     *     redirect_url?: string|null,
     *     max_uses?: int|string|null,
     *     expires_at?: string|null
     * } $data Payment link properties.
     * @return array<string, mixed> The newly created payment link database record fields.
     */
    public function create(int $merchantId, array $data): array
    {
        $slug = $data['slug'] ?? (preg_replace('/[^a-z0-9\-]+/', '', str_replace(' ', '-', strtolower($data['title'] ?? 'link'))) . '-' . substr(uniqid(), -4));
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $id = $this->db->insert(
            "INSERT INTO op_payment_links (merchant_id, uuid, slug, title, description, amount, currency, is_amount_fixed, require_address, min_amount, max_amount, redirect_url, max_uses, expires_at, status, created_at)
             VALUES (:mid, :uuid, :slug, :title, :desc, :amount, :cur, :fixed, :req_addr, :min, :max, :redir, :uses, :exp, 'active', NOW())",
            [
                'mid'   => $merchantId,
                'uuid'  => $uuid,
                'slug'  => $slug,
                'title' => $data['title'] ?? '',
                'desc'  => !empty($data['description']) ? $data['description'] : null,
                'amount' => !empty($data['amount']) ? $data['amount'] : null,
                'cur'   => $data['currency'] ?? 'BDT',
                'fixed' => isset($data['is_amount_fixed']) ? 1 : 0,
                'req_addr' => isset($data['require_address']) ? 1 : 0,
                'min'   => !empty($data['min_amount']) ? $data['min_amount'] : null,
                'max'   => !empty($data['max_amount']) ? $data['max_amount'] : null,
                'redir' => !empty($data['redirect_url']) ? $data['redirect_url'] : null,
                'uses'  => !empty($data['max_uses']) ? (int) $data['max_uses'] : null,
                'exp'   => !empty($data['expires_at']) ? $data['expires_at'] : null,
            ]
        );

        return $this->find($merchantId, (int) $id) ?? [];
    }

    /**
     * Updates an existing payment link.
     *
     * @param int $merchantId The unique ID of the merchant.
     * @param int $id The unique ID of the payment link.
     * @param array{
     *     title?: string,
     *     description?: string|null,
     *     amount?: float|int|string|null,
     *     currency?: string,
     *     require_address?: bool|int,
     *     status?: string
     * } $data Updated payment link fields.
     * @return array<string, mixed> The updated payment link database record fields.
     */
    public function update(int $merchantId, int $id, array $data): array
    {
        $this->db->update(
            "UPDATE op_payment_links SET title = :title, description = :desc, amount = :amount, currency = :cur, require_address = :req_addr, status = :st, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
            [
                'title'  => $data['title'] ?? '',
                'desc'   => $data['description'] ?? null,
                'amount' => $data['amount'] ?? null,
                'cur'    => $data['currency'] ?? 'BDT',
                'req_addr' => isset($data['require_address']) ? 1 : 0,
                'st'     => $data['status'] ?? 'active',
                'id'     => $id,
                'mid'    => $merchantId,
            ]
        );
        return $this->find($merchantId, $id) ?? [];
    }

    /**
     * Ensures that a merchant/brand has at least one active default payment link.
     *
     * This is typically executed during brand initialization. It is idempotent and skips
     * creation if the brand already has configured links.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param string $brandName The human-readable name of the brand/merchant.
     * @param string $brandSlug The clean URL-safe slug of the brand.
     * @param string $currency The default transaction currency (defaults to BDT).
     * @return void
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
        ]);
    }

    /**
     * Finds an active payment link using its URL slug (used during public checkout).
     *
     * @param string $slug The unique payment link URL slug.
     * @return array<string, mixed>|null The payment link record fields, or null if inactive or not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM op_payment_links WHERE slug = :slug AND status = 'active'",
            ['slug' => $slug]
        );
    }
}
