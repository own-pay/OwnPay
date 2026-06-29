<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;

use OwnPay\Service\System\PdfService;

/**
 * Manages the lifecycle of merchant invoices and their associated items.
 *
 * Provides capabilities for listing, paginating, searching, creating, and updating
 * invoices, calculating sub-totals, discounts, taxes, and generating PDF receipts.
 */
final class InvoiceService
{
    /**
     * @var Database The database service.
     */
    private Database $db;

    /**
     * @var PdfService The PDF generation service.
     */
    private PdfService $pdf;

    /**
     * InvoiceService constructor.
     *
     * @param Database $db Direct database service.
     * @param PdfService $pdf PDF generation service.
     */
    public function __construct(Database $db, PdfService $pdf)
    {
        $this->db = $db;
        $this->pdf = $pdf;
    }

    /**
     * Normalizes a user-supplied monetary input to a non-negative 2-decimal string.
     *
     * Avoids the (float) round-trip that corrupts large DECIMAL(15,2) values and
     * silently accepts scientific notation. Non-numeric input becomes '0.00';
     * negatives are clamped to '0.00'; sub-cent precision is truncated (never
     * rounded up, so a line item can't be inflated by a fraction of a cent).
     *
     * @param mixed $raw The raw amount value from request input.
     * @return numeric-string The normalized amount.
     */
    private function normalizeMoney(mixed $raw): string
    {
        $s = is_scalar($raw) && !is_bool($raw) ? trim((string) $raw) : '';
        if (!preg_match('/^\d{1,13}(\.\d+)?$/', $s) || !is_numeric($s)) {
            return '0.00';
        }
        return bcadd($s, '0', 2);
    }

    /**
     * Lists invoices belonging to a specific merchant brand.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param int $page The current page number for pagination.
     * @param int $perPage The number of items to fetch per page.
     * @return array<int, array<string, mixed>> The array of matching invoice records.
     */
    public function listForMerchant(?int $merchantId, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;
        $where = $merchantId === null ? '' : 'WHERE i.merchant_id = :mid';
        $params = ['lim' => $perPage, 'off' => $offset];
        if ($merchantId !== null) {
            $params['mid'] = $merchantId;
        }
        return $this->db->fetchAll(
            "SELECT i.*, c.name_enc as customer_name_enc
             FROM op_invoices i
             LEFT JOIN op_customers c ON c.id = i.customer_id
             {$where}
             ORDER BY i.created_at DESC LIMIT :lim OFFSET :off",
            $params
        );
    }

    /**
     * Computes pagination parameters for a merchant's invoices query.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param int $page The current page number.
     * @param int $perPage The size of each page.
     * @return array{page: int, per_page: int, total: int, pages: int, offset: int} Pagination metadata.
     */
    public function pagination(?int $merchantId, int $page = 1, int $perPage = 25): array
    {
        if ($merchantId === null) {
            $totalVal = $this->db->fetchColumn("SELECT COUNT(*) FROM op_invoices");
        } else {
            $totalVal = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM op_invoices WHERE merchant_id = :mid",
                ['mid' => $merchantId]
            );
        }
        $total = is_scalar($totalVal) ? (int) $totalVal : 0;
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => (int) ceil($total / max($perPage, 1)),
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    /**
     * Finds an invoice by its ID and scopes it to the merchant, loading line items.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param int $id The unique ID of the invoice.
     * @return array<string, mixed>|null The invoice record with lines, or null if not found.
     */
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

    /**
     * Creates a new invoice along with its associated line items.
     *
     * Automatically calculates sub-totals, taxes, discounts, and total values.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param array{
     *     invoice_number?: string,
     *     customer_id?: int|string,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     currency?: string,
     *     tax?: float|int|string,
     *     discount?: float|int|string,
     *     items?: array<int, array{description?: string, quantity?: int|string, unit_price?: float|int|string, amount?: float|int|string}>
     * } $data The invoice and line item fields.
     * @return array<string, mixed> The newly created invoice record.
     */
    public function create(int $merchantId, array $data): array
    {
        $number = $data['invoice_number'] ?? ('INV-' . strtoupper(substr(uniqid(), -8)));
        $token  = bin2hex(random_bytes(32));
        $uuid   = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $customerId = !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        $dueDate    = !empty($data['due_date']) ? $data['due_date'] : null;
        $notes      = !empty($data['notes']) ? $data['notes'] : null;
        $items = $data['items'] ?? [];
        $subtotal = '0.00';
        foreach ($items as &$item) {
            $qty   = (string) max(1, (int) ($item['quantity'] ?? 1));
            $price = $this->normalizeMoney($item['unit_price'] ?? $item['amount'] ?? 0);
            $item['quantity']   = (int) $qty;
            $item['unit_price'] = $price;
            $itemTotal = bcmul($qty, $price, 2);
            $item['total']      = $itemTotal;
            $subtotal = bcadd($subtotal, $itemTotal, 2);
        }
        unset($item);

        $tax      = $this->normalizeMoney($data['tax'] ?? 0);
        $discount = $this->normalizeMoney($data['discount'] ?? 0);
        $total    = bcadd($subtotal, $tax, 2);
        $total    = bcsub($total, $discount, 2);
        if (bccomp($total, '0.00', 2) < 0) {
            $total = '0.00';
        }

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

    /**
     * Updates an existing invoice, recalculating costs and rebuilding line items.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param int $id The unique ID of the invoice to update.
     * @param array{
     *     customer_id?: int|string,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     currency?: string,
     *     tax?: float|int|string,
     *     discount?: float|int|string,
     *     status?: string,
     *     items?: array<int, array{description?: string, quantity?: int|string, unit_price?: float|int|string, amount?: float|int|string}>
     * } $data The invoice and line item fields to update.
     * @return array<string, mixed> The updated invoice record, or empty array if not found.
     */
    public function update(int $merchantId, int $id, array $data): array
    {
        $invoice = $this->db->fetchOne(
            "SELECT id FROM op_invoices WHERE id = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $merchantId]
        );
        if ($invoice === null) {
            return [];
        }

        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new \InvalidArgumentException('Invoice update must include at least one line item.');
        }
        $subtotal = '0.00';
        foreach ($items as &$item) {
            $qty   = (string) max(1, (int) ($item['quantity'] ?? 1));
            $price = $this->normalizeMoney($item['unit_price'] ?? $item['amount'] ?? 0);
            $item['quantity']   = (int) $qty;
            $item['unit_price'] = $price;
            $itemTotal = bcmul($qty, $price, 2);
            $item['total']      = $itemTotal;
            $subtotal = bcadd($subtotal, $itemTotal, 2);
        }
        unset($item);

        $tax      = $this->normalizeMoney($data['tax'] ?? 0);
        $discount = $this->normalizeMoney($data['discount'] ?? 0);
        $total    = bcadd($subtotal, $tax, 2);
        $total    = bcsub($total, $discount, 2);
        if (bccomp($total, '0.00', 2) < 0) {
            $total = '0.00';
        }

        $status = $data['status'] ?? 'draft';
        $allowedStatuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        $customerId = !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        $dueDate    = !empty($data['due_date']) ? $data['due_date'] : null;
        $notes      = !empty($data['notes']) ? $data['notes'] : null;

        $currency = is_string($data['currency'] ?? null) ? $data['currency'] : 'BDT';

        $this->db->transaction(function () use (
            $id, $merchantId, $customerId, $subtotal, $tax, $discount, $total, $currency, $notes, $dueDate, $status, $items
        ): void {
            $this->db->update(
                "UPDATE op_invoices SET customer_id = :cust, subtotal = :sub, tax = :tax, discount = :dis, total = :total, currency = :cur, notes = :notes, due_date = :due, status = :status, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
                [
                    'cust'     => $customerId,
                    'sub'      => $subtotal,
                    'tax'      => $tax,
                    'dis'      => $discount,
                    'total'    => $total,
                    'cur'      => $currency,
                    'notes'    => $notes,
                    'due'      => $dueDate,
                    'status'   => $status,
                    'id'       => $id,
                    'mid'      => $merchantId
                ]
            );

            $this->db->execute(
                "DELETE FROM op_invoice_items WHERE invoice_id = :id",
                ['id' => $id]
            );

            foreach ($items as $i => $item) {
                $this->db->insert(
                    "INSERT INTO op_invoice_items (invoice_id, description, quantity, unit_price, total, sort_order) VALUES (:inv, :desc, :qty, :price, :total, :sort)",
                    [
                        'inv'   => $id,
                        'desc'  => $item['description'] ?? '',
                        'qty'   => $item['quantity'],
                        'price' => $item['unit_price'],
                        'total' => $item['total'],
                        'sort'  => $i
                    ]
                );
            }
        });

        return $this->find($merchantId, $id) ?? [];
    }

    /**
     * Generates a PDF representing the specified invoice.
     *
     * @param int $merchantId The brand/merchant ID.
     * @param int $id The unique ID of the invoice.
     * @return string A serialized JSON/HTML string acting as the PDF content.
     */
    public function generatePdf(int $merchantId, int $id): string
    {
        $invoice = $this->find($merchantId, $id);
        if (!$invoice) {
            return '';
        }

        $template = <<<HTML
        <h2>Invoice #{{invoice_number}}</h2>
        <p><strong>Merchant:</strong> {{merchant_name}}</p>
        <p><strong>Date:</strong> {{created_at}}</p>
        <p><strong>Status:</strong> {{status}}</p>
        <p><strong>Amount:</strong> {{amount}} {{currency}}</p>
        
        <h3>Line Items</h3>
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                {{items_rows}}
            </tbody>
        </table>
        HTML;

        $filePath = $this->pdf->generateInvoice($invoice, $template);
        if (file_exists($filePath)) {
            $content = @file_get_contents($filePath);
            return is_string($content) ? $content : '';
        }

        $json = json_encode($invoice);
        return is_string($json) ? $json : '';
    }
}
