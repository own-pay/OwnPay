<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;
use OwnPay\Core\UuidGenerator;
use OwnPay\Support\DateHelper;

/**
 * Repository for the enterprise double-entry ledger system.
 * 
 * Manages accounts (`op_ledger_accounts`), journal transactions/headers 
 * (`op_ledger_transactions`), and debit/credit entry lines (`op_ledger_entries`).
 * 
 * Double-entry ledger operations post balanced debits and credits across accounts 
 * scoped strictly by merchant ID and name to prevent cross-brand leakage and type 
 * mismatches on liability accounts (e.g., MERCHANT_PAYABLE as a liability account 
 * and others as assets).
 * 
 * @package OwnPay\Repository
 */
final class LedgerRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name for ledger accounts.
     */
    protected string $table = 'op_ledger_accounts';

    // --- Accounts ----------------------------------------

    /**
     * Finds an existing ledger account or creates one if it does not exist.
     * 
     * Scopes accounts strictly by merchant_id and name to prevent cross-brand leakage
     * and type mismatches on liability/asset accounts.
     * 
     * @param string $name The name of the ledger account (e.g., 'MERCHANT_PAYABLE').
     * @param string $type The account type (e.g., 'asset', 'liability', 'expense', 'equity', 'revenue').
     * @param string $currency The ISO currency code (defaults to 'BDT').
     * @param int|null $merchantId Optional brand/merchant ID override (defaults to current tenantId).
     * @return array<string, mixed> The resolved ledger account record.
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

        $account = $this->find($id);
        if ($account === null) {
            throw new \RuntimeException("Failed to retrieve newly created ledger account.");
        }

        return $account;
    }

    /**
     * Retrieves the current balance of a given ledger account.
     * 
     * @param int $accountId The unique ledger account ID.
     * @return string The decimal string balance, defaulting to '0.00' if not found.
     */
    public function getBalance(int $accountId): string
    {
        $row = $this->find($accountId);
        $balance = $row['balance'] ?? '0.00';
        return is_scalar($balance) ? (string) $balance : '0.00';
    }

    /**
     * Atomically adjusts the balance of a ledger account.
     * 
     * Follows standard double-entry bookkeeping rules:
     * - Asset / Expense: debit increases (+), credit decreases (-)
     * - Liability / Equity / Revenue: credit increases (+), debit decreases (-)
     * 
     * @param int $accountId The unique ledger account ID.
     * @param string $amount The positive decimal string amount to adjust.
     * @param string $entryType The type of adjustment ('debit' or 'credit').
     * @return void
     */
    public function adjustBalance(int $accountId, string $amount, string $entryType): void
    {
        $account = $this->find($accountId);
        if ($account === null) {
            return;
        }

        $typeVal = $account['type'] ?? '';
        $type = strtolower(is_scalar($typeVal) ? (string) $typeVal : '');
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

    // --- Journal Transactions

    /**
     * Creates a new ledger transaction (journal header).
     * 
     * Represents a single financial event referencing a business object 
     * (e.g., transaction, payout, invoice) and containing multiple entry lines.
     * 
     * @param string $referenceType The type of reference (e.g., 'transaction').
     * @param int $referenceId The database ID of the referenced object.
     * @param string|null $description Optional descriptive text for the journal entry.
     * @return int The primary key ID of the newly created journal transaction.
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

    // --- Ledger Entries

    /**
     * Creates a new individual ledger entry line (debit or credit).
     * 
     * Must be associated with a valid ledger transaction header.
     * 
     * @param int $ledgerTransactionId The parent ledger transaction ID.
     * @param int $accountId The target ledger account ID.
     * @param string $type The entry type, either 'debit' or 'credit'.
     * @param string $amount The entry amount as a decimal string.
     * @return int The primary key ID of the newly created ledger entry.
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
     * Retrieves all ledger entries associated with a journal transaction.
     * 
     * @param int $ledgerTransactionId The parent ledger transaction ID.
     * @return array<int, array<string, mixed>> List of matching ledger entry records.
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
     * Verifies that the debits and credits of a journal transaction balance out.
     * 
     * Compares the sum of debit amounts to the sum of credit amounts using BCMath.
     * 
     * @param int $ledgerTransactionId The parent ledger transaction ID.
     * @return bool True if total debits match total credits (invariant satisfied), false otherwise.
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

        $totalDebitVal = $row['total_debit'] ?? '0';
        $totalCreditVal = $row['total_credit'] ?? '0';
        $totalDebit = is_scalar($totalDebitVal) ? (string) $totalDebitVal : '0';
        $totalCredit = is_scalar($totalCreditVal) ? (string) $totalCreditVal : '0';

        if (!is_numeric($totalDebit)) {
            $totalDebit = '0';
        }
        if (!is_numeric($totalCredit)) {
            $totalCredit = '0';
        }

        return bccomp($totalDebit, $totalCredit, 4) === 0;
    }

    /**
     * Retrieves a paginated list of ledger transactions (with entry summaries) for a merchant.
     * 
     * @param int $merchantId The merchant ID.
     * @param int $page The current page number (1-indexed).
     * @param int $limit The number of records to return per page.
     * @return array{items: array<int, array<string, mixed>>, total: int} A structure containing the paginated rows and total count.
     */
    public function entriesPaginated(?int $merchantId, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        // merchantId === null => global "All Brands" view: aggregate entries across brands.
        $countWhere = $merchantId === null ? '' : 'WHERE merchant_id = :mid';
        $listWhere  = $merchantId === null ? '' : 'WHERE lt.merchant_id = :mid';
        $countParams = $merchantId === null ? [] : ['mid' => $merchantId];

        $totalVal = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM op_ledger_transactions {$countWhere}",
            $countParams
        );
        $total = is_scalar($totalVal) ? (int) $totalVal : 0;

        $listParams = ['lim' => $limit, 'off' => $offset];
        if ($merchantId !== null) {
            $listParams['mid'] = $merchantId;
        }
        $items = $this->db->fetchAll(
            "SELECT lt.id, lt.uuid, lt.description, lt.reference_type, lt.reference_id, lt.created_at,
                    COALESCE(MIN(la.currency), 'BDT') as currency,
                    SUM(CASE WHEN le.type = 'debit' THEN le.amount ELSE 0 END) as total_amount,
                    'posted' as status,
                    CASE WHEN lt.reference_type = 'transaction' THEN 'payment_received' ELSE lt.reference_type END as event_type,
                    GROUP_CONCAT(CONCAT(le.type, ':', le.amount) ORDER BY le.id) as entries_summary
             FROM op_ledger_transactions lt
             LEFT JOIN op_ledger_entries le ON le.ledger_transaction_id = lt.id
             LEFT JOIN op_ledger_accounts la ON la.id = le.account_id
             {$listWhere}
             GROUP BY lt.id
             ORDER BY lt.created_at DESC
             LIMIT :lim OFFSET :off",
            $listParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Calculates the total balance for a merchant's 'MERCHANT_PAYABLE' liability account in a given currency.
     * 
     * @param int $merchantId The merchant ID.
     * @param string $currency The ISO currency code (defaults to BDT).
     * @return string The decimal balance as a string.
     */
    public function merchantBalance(int $merchantId, string $currency = 'BDT'): string
    {
        $row = $this->db->fetchOne(
            "SELECT balance FROM op_ledger_accounts
             WHERE merchant_id = :mid AND currency = :cur AND name = 'MERCHANT_PAYABLE'
             LIMIT 1",
            ['mid' => $merchantId, 'cur' => $currency]
        );
        $balance = $row['balance'] ?? '0.00';
        return is_scalar($balance) ? (string) $balance : '0.00';
    }
}

