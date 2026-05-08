<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;

/**
 * Invoice service â€” CRUD for merchant invoices.
 */
final class InvoiceService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function listForMerchant(int $merchantId, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;
        return $this->db->fetchAll(
            "SELECT i.*, c.name_enc as customer_name_enc
             FROM op_invoices i
             LEFT JOIN op_customers c ON c.id = i.customer_id
             WHERE i.merchant_id = :mid
             ORDER BY i.created_at DESC LIMIT :lim OFFSET :off",
            ['mid' => $merchantId, 'lim' => $perPage, 'off' => $offset]
        );
    }

    public function pagination(int $merchantId, int $page = 1, int $perPage = 25): array
    {
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_invoices WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => (int) ceil($total / max($perPage, 1)),
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    public function find(int $merchantId, int $id): ?array
    {
        $invoice = $this->db->fetchOne(
            "SELECT * FROM op_invoices WHERE id = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $merchantId]
        );
        if ($invoice === null) {
            return null;
        }
        $invoice['items'] = $this->db->fetchAll(
            "SELECT * FROM op_invoice_items WHERE invoice_id = :id ORDER BY sort_order",
            ['id' => $id]
        );
        return $invoice;
    }

    public function create(int $merchantId, array $data): array
    {
        $number = $data['invoice_number'] ?? ('INV-' . strtoupper(substr(uniqid(), -8)));
        $token  = bin2hex(random_bytes(32));
        $uuid   = \Ramsey\Uuid\Uuid::uuid4()->toString();

        // Sanitize: empty strings → null for nullable DB columns
        $customerId = !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        $dueDate    = !empty($data['due_date']) ? $data['due_date'] : null;
        $notes      = !empty($data['notes']) ? $data['notes'] : null;

        // Calculate totals from line items
        $items = $data['items'] ?? [];
        $subtotal = 0;
        foreach ($items as &$item) {
            $qty   = max(1, (int) ($item['quantity'] ?? 1));
            $price = (float) ($item['unit_price'] ?? $item['amount'] ?? 0);
            $item['quantity']   = $qty;
            $item['unit_price'] = $price;
            $item['total']      = $qty * $price;
            $subtotal += $item['total'];
        }
        unset($item);

        $tax      = (float) ($data['tax'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);
        $total    = $subtotal + $tax - $discount;

        $id = $this->db->insert(
            "INSERT INTO op_invoices (merchant_id, uuid, token, invoice_number, customer_id, subtotal, tax, discount, total, currency, notes, due_date, status, created_at, updated_at)
             VALUES (:mid, :uuid, :token, :num, :cust, :sub, :tax, :dis, :total, :cur, :notes, :due, 'draft', NOW(), NOW())",
            [
                'mid'   => $merchantId,
                'uuid'  => $uuid,
                'token' => $token,
                'num'   => $number,
                'cust'  => $customerId,
                'sub'   => $subtotal,
                'tax'   => $tax,
                'dis'   => $discount,
                'total' => $total,
                'cur'   => $data['currency'] ?? 'BDT',
                'notes' => $notes,
                'due'   => $dueDate,
            ]
        );

        foreach ($items as $i => $item) {
            $this->db->insert(
                "INSERT INTO op_invoice_items (invoice_id, description, quantity, unit_price, total, sort_order) VALUES (:inv, :desc, :qty, :price, :total, :sort)",
                ['inv' => $id, 'desc' => $item['description'] ?? '', 'qty' => $item['quantity'], 'price' => $item['unit_price'], 'total' => $item['total'], 'sort' => $i]
            );
        }

        return $this->find($merchantId, (int) $id) ?? [];
    }

    public function update(int $merchantId, int $id, array $data): array
    {
        $subtotal = array_sum(array_column($data['items'] ?? [], 'total'));
        $tax      = (float) ($data['tax'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);
        $total    = $subtotal + $tax - $discount;

        $this->db->update(
            "UPDATE op_invoices SET customer_id = :cust, subtotal = :sub, tax = :tax, discount = :dis, total = :total, currency = :cur, notes = :notes, due_date = :due, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
            ['cust' => $data['customer_id'] ?? null, 'sub' => $subtotal, 'tax' => $tax, 'dis' => $discount, 'total' => $total, 'cur' => $data['currency'] ?? 'BDT', 'notes' => $data['notes'] ?? null, 'due' => $data['due_date'] ?? null, 'id' => $id, 'mid' => $merchantId]
        );

        return $this->find($merchantId, $id) ?? [];
    }

    public function generatePdf(int $merchantId, int $id): string
    {
        // Stub â€” return HTML as PDF placeholder
        $invoice = $this->find($merchantId, $id);
        return $invoice ? json_encode($invoice) : '';
    }
}
