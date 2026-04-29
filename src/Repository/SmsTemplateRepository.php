<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * SmsTemplateRepository — CRUD for `op_sms_templates`.
 *
 * Manages regex templates used by the Tier 1 SMS parsing engine.
 * Templates are ordered by priority (lower = try first) and filtered by sender.
 */
final class SmsTemplateRepository
{
    private const TABLE = 'op_sms_templates';

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Get all active templates matching a sender, ordered by priority.
     *
     * @return array List of template rows
     */
    public function findBySender(string $sender): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . "
             WHERE is_active = 1 AND (
                 :sender LIKE CONCAT('%', sender_pattern, '%')
                 OR sender_pattern = :sender_exact
             )
             ORDER BY priority ASC"
        );
        $stmt->execute([':sender' => $sender, ':sender_exact' => $sender]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all active templates, ordered by priority.
     */
    public function findAllActive(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM " . self::TABLE . " WHERE is_active = 1 ORDER BY priority ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a template by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new template.
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . self::TABLE . " (sender_pattern, regex_pattern, transaction_type, provider_name, currency, balance_verify, priority, is_active, description)
             VALUES (:sender_pattern, :regex_pattern, :transaction_type, :provider_name, :currency, :balance_verify, :priority, :is_active, :description)"
        );
        $stmt->execute([
            ':sender_pattern'   => $data['sender_pattern'],
            ':regex_pattern'    => $data['regex_pattern'],
            ':transaction_type' => $data['transaction_type'] ?? 'credit',
            ':provider_name'    => $data['provider_name'],
            ':currency'         => $data['currency'] ?? 'BDT',
            ':balance_verify'   => $data['balance_verify'] ?? 1,
            ':priority'         => $data['priority'] ?? 100,
            ':is_active'        => $data['is_active'] ?? 1,
            ':description'      => $data['description'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a template.
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['sender_pattern', 'regex_pattern', 'transaction_type', 'provider_name', 'currency', 'balance_verify', 'priority', 'is_active', 'description'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE " . self::TABLE . " SET " . implode(', ', $fields) . " WHERE id = :id";
        return $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Delete a template.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM " . self::TABLE . " WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get all active providers grouped for MfsService.
     */
    public function findAllProviders(): array
    {
        $stmt = $this->pdo->query(
            "SELECT provider_name, currency, balance_verify, sender_pattern 
             FROM " . self::TABLE . " 
             WHERE is_active = 1 
             ORDER BY provider_name ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
