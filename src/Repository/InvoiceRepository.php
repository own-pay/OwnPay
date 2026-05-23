<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

/**
 * Repository layer for invoices (`op_invoices` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages invoice numbers generation, unique security checkout tokens,
 * invoice items querying, and link lookups to pending transactions.
 */
final class InvoiceRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_invoices';
    protected array $fillable = [
        'merchant_id', 'uuid', 'token', 'invoice_number', 'customer_id',
        'subtotal', 'tax', 'discount', 'total', 'currency', 'notes',
        'due_date', 'status', 'paid_at',
    ];

    /**
     * Creates a new invoice with UUID and dynamic token.
     *
     * @param array<string, mixed> $data Invoice creation attributes.
     * @return string Last inserted primary key ID.
     */
    public function createInvoice(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['token'] = bin2hex(random_bytes(32));
        $data['invoice_number'] = $data['invoice_number'] ?? $this->generateNumber();
        return $this->createScoped($data);
    }

    /**
     * Finds an invoice record by its unique secure checkout token.
     *
     * Public helper; intentionally unscoped.
     *
     * @param string $token Secure checkout token.
     * @return array<string, mixed>|null Invoice database record, or null if not found.
     */
    public function findByToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE token = :t LIMIT 1",
            ['t' => $token]
        );
    }

    /**
     * Generates a unique invoice number for the active merchant.
     *
     * @return string Generated invoice number.
     */
    private function generateNumber(): string
    {
        $mid = $this->requireTenant();
        $count = $this->countScoped() + 1;
        return 'INV-' . str_pad((string) $count, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Finds an unpaid invoice by number.
     *
     * Scoped by merchant_id to prevent cross-tenant leakage.
     *
     * @param string $invoiceNumber Invoice sequence number.
     * @param int $merchantId Scoping merchant ID context.
     * @return array<string, mixed>|null Invoice database record, or null if not found/paid.
     */
    public function findUnpaidByNumber(string $invoiceNumber, int $merchantId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE invoice_number = :num AND merchant_id = :mid AND status != 'paid'",
            ['num' => $invoiceNumber, 'mid' => $merchantId]
        );
    }

    /**
     * Finds a pending transaction associated with an invoice.
     *
     * Leverages the STORED generated indexing column `invoice_id` in `op_transactions`
     * for high-performance direct lookups.
     *
     * @param int $invoiceId Primary key identifier of the invoice.
     * @return array<string, mixed>|null Pending transaction row (with key trx_id) or null.
     */
    public function findPendingTransaction(int $invoiceId): ?array
    {
        return $this->db->fetchOne(
            "SELECT trx_id FROM op_transactions
             WHERE invoice_id = :iid AND status = 'pending'
             LIMIT 1",
            ['iid' => $invoiceId]
        );
    }

    /**
     * Lists invoice items for a specific invoice.
     *
     * @param int $invoiceId Primary key identifier of the invoice.
     * @return array<int, array<string, mixed>> List of invoice item rows.
     */
    public function listItems(int $invoiceId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM op_invoice_items WHERE invoice_id = :iid",
            ['iid' => $invoiceId]
        );
    }
}
