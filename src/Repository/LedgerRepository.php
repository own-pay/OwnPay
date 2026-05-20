<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;
use OwnPay\Core\UuidGenerator;
use OwnPay\Support\DateHelper;

/**
 * Repository for the double-entry ledger system:
 *   - op_ledger_accounts
 *   - op_ledger_transactions (journal headers)
 *   - op_ledger_entries (debit/credit lines)
 */
final class LedgerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_ledger_accounts';

    // ——— Accounts ————————————————————————————————————————

    /**
     * Find or create a ledger account by name and type.
     */
    public function findOrCreateAccount(
        string $name,
        string $type,
        string $currency = 'BDT',
        ?int $merchantId = null
    ): array {
        $mid = $merchantId ?? $this->tenantId;

        $where = '`name` = :name AND `currency` = :cur';
        $params = ['name' => $name, 'cur' => $currency];

        if ($mid !== null) {
            $where .= ' AND `merchant_id` = :mid';
            $params['mid'] = $mid;
        } else {
            $where .= ' AND `merchant_id` IS NULL';
        }

        $account = $this->db->fetchOne("SELECT * FROM {$this->table} WHERE {$where} LIMIT 1", $params);

        if ($account !== null) {
            return $account;
        }

        $id = $this->insert([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'merchant_id' => $mid,
            'balance' => '0.00',
        ]);

        return $this->find($id);
    }

    /**
     * Get account balance.
     */
    public function getBalance(int $accountId): string
    {
        $row = $this->find($accountId);
        return $row['balance'] ?? '0.00';
    }

    /**
     * Atomically adjust account balance.
     */
    public function adjustBalance(int $accountId, string $amount, string $entryType): void
    {
        $account = $this->find($accountId);
        if ($account === null) {
            return;
        }

        $type = strtolower($account['type']);
        $entryType = strtolower($entryType);

        // Double-entry rules
        // Asset/Expense: debit increases (+), credit decreases (-)
        // Liability/Equity/Revenue: credit increases (+), debit decreases (-)
        $isIncrease = false;
        if (in_array($type, ['asset', 'expense'], true)) {
            $isIncrease = ($entryType === 'debit');
        } elseif (in_array($type, ['liability', 'equity', 'revenue'], true)) {
            $isIncrease = ($entryType === 'credit');
        }

        $operator = $isIncrease ? '+' : '-';

        $where = "`id` = :id";
        $params = ['amount' => $amount, 'id' => $accountId];
        if ($this->tenantId !== null) {
            $where .= " AND `merchant_id` = :mid";
            $params['mid'] = $this->requireTenant();
        }
        $this->db->execute(
            "UPDATE `op_ledger_accounts` SET `balance` = `balance` {$operator} :amount WHERE {$where}",
            $params
        );
    }

    // ——— Journal Transactions ————————————————————————————

    /**
     * Create a ledger transaction (journal header).
     */
    public function createTransaction(
        string $referenceType,
        int $referenceId,
        ?string $description = null
    ): int {
        $uuid = UuidGenerator::generate();
        $now = DateHelper::nowMicro();
        $mid = $this->tenantId;

        $this->db->execute(
            "INSERT INTO `op_ledger_transactions`
             (`merchant_id`, `uuid`, `description`, `reference_type`, `reference_id`, `created_at`)
             VALUES (:mid, :uuid, :desc, :rt, :ri, :ca)",
            [
                'mid' => $mid,
                'uuid' => $uuid,
                'desc' => $description,
                'rt' => $referenceType,
                'ri' => $referenceId,
                'ca' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // ——— Ledger Entries ——————————————————————————————————

    /**
     * Create a ledger entry (debit or credit line).
     */
    public function createEntry(
        int $ledgerTransactionId,
        int $accountId,
        string $type,
        string $amount
    ): int {
        $now = DateHelper::nowMicro();

        $this->db->execute(
            "INSERT INTO `op_ledger_entries`
             (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`)
             VALUES (:ltid, :aid, :type, :amt, :ca)",
            [
                'ltid' => $ledgerTransactionId,
                'aid' => $accountId,
                'type' => $type,
                'amt' => $amount,
                'ca' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get all entries for a journal transaction.
     */
    public function getEntries(int $ledgerTransactionId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `op_ledger_entries`
             WHERE `ledger_transaction_id` = :ltid
             ORDER BY `account_id` ASC",
            ['ltid' => $ledgerTransactionId]
        );
    }

    /**
     * Verify balanced invariant for a journal transaction.
     */
    public function isBalanced(int $ledgerTransactionId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT
                SUM(CASE WHEN `type` = 'debit'  THEN `amount` ELSE 0 END) AS total_debit,
                SUM(CASE WHEN `type` = 'credit' THEN `amount` ELSE 0 END) AS total_credit
             FROM `op_ledger_entries`
             WHERE `ledger_transaction_id` = :ltid",
            ['ltid' => $ledgerTransactionId]
        );

        if ($row === null) {
            return false;
        }

        return bccomp($row['total_debit'] ?? '0', $row['total_credit'] ?? '0', 4) === 0;
    }

    /**
     * Paginated ledger entries for a merchant.
     */
    public function entriesPaginated(int $merchantId, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_ledger_transactions WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );

        $items = $this->db->fetchAll(
            "SELECT lt.id, lt.uuid, lt.description, lt.reference_type, lt.reference_id, lt.created_at,
                    GROUP_CONCAT(CONCAT(le.type, ':', le.amount) ORDER BY le.id) as entries_summary
             FROM op_ledger_transactions lt
             LEFT JOIN op_ledger_entries le ON le.ledger_transaction_id = lt.id
             WHERE lt.merchant_id = :mid
             GROUP BY lt.id
             ORDER BY lt.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            ['mid' => $merchantId]
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Calculate total balance for a merchant in a given currency.
     */
    public function merchantBalance(int $merchantId, string $currency = 'BDT'): string
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(balance), 0) as total FROM op_ledger_accounts
             WHERE merchant_id = :mid AND currency = :cur",
            ['mid' => $merchantId, 'cur' => $currency]
        );
        return $row['total'] ?? '0.00';
    }
}
