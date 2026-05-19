<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

final class InvoiceRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_invoices';
    protected array $fillable = [
        'merchant_id', 'uuid', 'token', 'invoice_number', 'customer_id',
        'subtotal', 'tax', 'discount', 'total', 'currency', 'notes',
        'due_date', 'status', 'paid_at',
    ];

    public function createInvoice(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['token'] = bin2hex(random_bytes(32));
        $data['invoice_number'] = $data['invoice_number'] ?? $this->generateNumber();
        return $this->createScoped($data);
    }

    public function findByToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE token = :t LIMIT 1",
            ['t' => $token]
        );
    }

    private function generateNumber(): string
    {
        $mid = $this->requireTenant();
        $count = $this->countScoped() + 1;
        return 'INV-' . str_pad((string) $count, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Find unpaid invoice by number (public checkout).
     */
    public function findUnpaidByNumber(string $invoiceNumber): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE invoice_number = :num AND status != 'paid'",
            ['num' => $invoiceNumber]
        );
    }

    /**
     * Find pending transaction for invoice.
     * C-02 FIX: op_transactions has no invoice_id column — ID stored in metadata JSON.
     */
    public function findPendingTransaction(int $invoiceId): ?array
    {
        return $this->db->fetchOne(
            "SELECT trx_id FROM op_transactions
             WHERE JSON_EXTRACT(metadata, '$.invoice_id') = :iid AND status = 'pending'
             LIMIT 1",
            ['iid' => $invoiceId]
        );
    }

    /**
     * List invoice items.
     */
    public function listItems(int $invoiceId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM op_invoice_items WHERE invoice_id = :iid",
            ['iid' => $invoiceId]
        );
    }
}
