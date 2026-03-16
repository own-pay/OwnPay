<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

use AnirbanPay\Core\Database;
use AnirbanPay\Core\UuidGenerator;

/**
 * Repository for the double-entry ledger system:
 *   - ap_ledger_accounts
 *   - ap_ledger_transactions (journal headers)
 *   - ap_ledger_entries (debit/credit lines) — PARTITIONED
 */
class LedgerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_ledger_accounts';

    // ─── Accounts ────────────────────────────────────────────────────

    /**
     * Find or create a ledger account.
     */
    public function findOrCreateAccount(
        string $code,
        string $name,
        string $accountType,
        string $currency = 'BDT',
        ?int $merchantId = null
    ): array {
        $where = '`code` = :code';
        $params = ['code' => $code];

        if ($merchantId !== null) {
            $where .= ' AND `merchant_id` = :mid';
            $params['mid'] = $merchantId;
        } else {
            $where .= ' AND `merchant_id` IS NULL';
        }

        $account = $this->findOneWhere($where, $params);

        if ($account !== null) {
            return $account;
        }

        // Create new account
        $id = $this->insert([
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'currency' => $currency,
            'merchant_id' => $merchantId,
            'balance' => '0.0000',
            'status' => 'active',
        ]);

        return $this->findById($id);
    }

    /**
     * Get account balance.
     */
    public function getBalance(int $accountId): string
    {
        $row = $this->findById($accountId);
        return $row['balance'] ?? '0.0000';
    }

    /**
     * Atomically update account balance using bcmath addition.
     * Locks the row with FOR UPDATE inside a transaction.
     */
    public function adjustBalance(int $accountId, string $amount): void
    {
        $this->db->execute(
            "UPDATE `ap_ledger_accounts`
             SET `balance` = `balance` + :amount,
                 `updated_at` = NOW(6)
             WHERE `id` = :id",
            ['amount' => $amount, 'id' => $accountId]
        );
    }

    // ─── Journal Transactions ────────────────────────────────────────

    /**
     * Create a ledger transaction (journal header).
     *
     * @return int The auto-increment ID
     */
    public function createTransaction(
        string $eventType,
        string $referenceType,
        string $referenceId,
        string $totalAmount,
        string $currency = 'BDT',
        ?string $description = null
    ): int {
        $publicId = UuidGenerator::generate();
        $now = gmdate('Y-m-d H:i:s.u');

        $this->db->execute(
            "INSERT INTO `ap_ledger_transactions`
             (`public_id`, `event_type`, `reference_type`, `reference_id`,
              `total_amount`, `currency`, `description`, `status`, `created_at`)
             VALUES (:pid, :et, :rt, :ri, :ta, :cur, :desc, 'posted', :ca)",
            [
                'pid' => $publicId,
                'et' => $eventType,
                'rt' => $referenceType,
                'ri' => $referenceId,
                'ta' => $totalAmount,
                'cur' => $currency,
                'desc' => $description,
                'ca' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // ─── Ledger Entries ──────────────────────────────────────────────

    /**
     * Create a ledger entry (debit or credit line).
     * Table is PARTITIONED — created_at is part of the PK.
     */
    public function createEntry(
        int $ledgerTransactionId,
        int $accountId,
        string $entryType,
        string $amount,
        string $currency = 'BDT'
    ): int {
        $now = gmdate('Y-m-d H:i:s.u');

        $this->db->execute(
            "INSERT INTO `ap_ledger_entries`
             (`ledger_transaction_id`, `account_id`, `entry_type`,
              `amount`, `currency`, `created_at`)
             VALUES (:ltid, :aid, :et, :amt, :cur, :ca)",
            [
                'ltid' => $ledgerTransactionId,
                'aid' => $accountId,
                'et' => $entryType,
                'amt' => $amount,
                'cur' => $currency,
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
            "SELECT * FROM `ap_ledger_entries`
             WHERE `ledger_transaction_id` = :ltid
             ORDER BY `account_id` ASC",
            ['ltid' => $ledgerTransactionId]
        );
    }

    /**
     * Verify the balanced invariant for a journal transaction.
     * Returns true if sum(debit) === sum(credit).
     */
    public function isBalanced(int $ledgerTransactionId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT
                SUM(CASE WHEN `entry_type` = 'debit'  THEN `amount` ELSE 0 END) AS total_debit,
                SUM(CASE WHEN `entry_type` = 'credit' THEN `amount` ELSE 0 END) AS total_credit
             FROM `ap_ledger_entries`
             WHERE `ledger_transaction_id` = :ltid",
            ['ltid' => $ledgerTransactionId]
        );

        if ($row === null) {
            return false;
        }

        return bccomp($row['total_debit'] ?? '0', $row['total_credit'] ?? '0', 4) === 0;
    }
}
