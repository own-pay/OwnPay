<?php

declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Repository\CustomerRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Security\PiiMasker;

/**
 * CustomerPiiService — PII lifecycle management.
 *
 * Handles encryption at rest, decryption on read, GDPR data portability,
 * and right-to-erasure operations.
 *
 * PII fields encrypted: name, email, phone/mobile, address
 * Blind indexes stored for: email (for uniqueness/search)
 */
final class CustomerPiiService
{
    /** Fields that contain PII and must be encrypted at rest. */
    private const PII_FIELDS = ['name', 'email', 'phone', 'mobile', 'address'];

    /** Fields that get a blind index for searchability. */
    private const INDEXED_FIELDS = ['email', 'phone'];

    private CustomerRepository $customers;
    private FieldEncryptor $encryptor;
    private PiiMasker $masker;
    private AuditLogger $audit;

    public function __construct(
        ?CustomerRepository $customers = null,
        ?FieldEncryptor $encryptor = null,
        ?PiiMasker $masker = null,
        ?AuditLogger $audit = null
    ) {
        $this->customers = $customers ?? new CustomerRepository();
        $this->encryptor = $encryptor ?? new FieldEncryptor();
        $this->masker = $masker ?? new PiiMasker();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Store customer data with PII fields encrypted.
     *
     * @param array $data Customer data with plaintext PII
     * @return int Customer ID
     */
    public function store(array $data): int
    {
        $encrypted = $this->encryptPiiFields($data);
        return $this->customers->insert($encrypted);
    }

    /**
     * Retrieve a customer with decrypted PII.
     *
     * @param int $id Customer ID
     * @return array|null Customer data with plaintext PII
     */
    public function retrieve(int $id): ?array
    {
        $customer = $this->customers->findById($id);
        if ($customer === null) {
            return null;
        }

        return $this->decryptPiiFields($customer);
    }

    /**
     * Retrieve and mask customer data (for API responses).
     *
     * @param int $id Customer ID
     * @return array|null Customer data with masked PII
     */
    public function retrieveMasked(int $id): ?array
    {
        $decrypted = $this->retrieve($id);
        if ($decrypted === null) {
            return null;
        }

        return $this->masker->mask($decrypted);
    }

    /**
     * Search for a customer by encrypted field using blind index.
     *
     * @param string $field Field name (must be in INDEXED_FIELDS)
     * @param string $value Plaintext value to search for
     * @return array|null Customer data (decrypted) or null
     */
    public function findByPiiField(string $field, string $value): ?array
    {
        if (!in_array($field, self::INDEXED_FIELDS, true)) {
            throw new \InvalidArgumentException("Field '{$field}' is not indexed for PII search.");
        }

        $blindIndex = $this->encryptor->blindIndex($value);
        $indexColumn = "{$field}_idx";

        $customer = $this->customers->findOneWhere(
            "`{$indexColumn}` = :idx",
            ['idx' => $blindIndex]
        );

        if ($customer === null) {
            return null;
        }

        return $this->decryptPiiFields($customer);
    }

    /**
     * GDPR: Export all customer data as decrypted JSON.
     * Data portability — returns raw data for customer download.
     *
     * @param int $customerId
     * @param int $merchantId Merchant scoping for access control
     * @return array Decrypted customer data
     */
    public function export(int $customerId, int $merchantId): array
    {
        $customer = $this->retrieve($customerId);
        if ($customer === null || (int) ($customer['merchant_id'] ?? 0) !== $merchantId) {
            throw new \InvalidArgumentException('Customer not found or access denied.');
        }

        $this->audit->log(
            $merchantId,
            'pii.exported',
            'customer',
            $customer['public_id'] ?? (string) $customerId,
            'system',
            'customer_pii_service',
            null,
            ['reason' => 'data_portability_request']
        );

        // Remove internal fields
        unset($customer['id'], $customer['key_hash']);
        foreach (self::INDEXED_FIELDS as $field) {
            unset($customer["{$field}_idx"]);
        }

        return $customer;
    }

    /**
     * GDPR: Purge (right to erasure).
     * Nullifies all PII columns and blind indexes.
     *
     * @param int $customerId
     * @param int $merchantId Merchant scoping
     */
    public function purge(int $customerId, int $merchantId): void
    {
        $customer = $this->customers->findById($customerId);
        if ($customer === null || (int) ($customer['merchant_id'] ?? 0) !== $merchantId) {
            throw new \InvalidArgumentException('Customer not found or access denied.');
        }

        $nullFields = [];
        foreach (self::PII_FIELDS as $field) {
            if (isset($customer[$field])) {
                $nullFields[$field] = null;
            }
        }
        foreach (self::INDEXED_FIELDS as $field) {
            $nullFields["{$field}_idx"] = null;
        }

        $this->customers->updateById($customerId, $nullFields);

        $this->audit->log(
            $merchantId,
            'pii.purged',
            'customer',
            $customer['public_id'] ?? (string) $customerId,
            'system',
            'customer_pii_service',
            ['pii_fields' => array_keys($nullFields)],
            ['pii_fields' => 'PURGED']
        );
    }

    /**
     * Encrypt PII fields in a data array.
     */
    private function encryptPiiFields(array $data): array
    {
        foreach (self::PII_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                // Store blind index for indexed fields
                if (in_array($field, self::INDEXED_FIELDS, true)) {
                    $data["{$field}_idx"] = $this->encryptor->blindIndex($data[$field]);
                }
                $data[$field] = $this->encryptor->encrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Decrypt PII fields in a data array.
     */
    private function decryptPiiFields(array $data): array
    {
        foreach (self::PII_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $this->encryptor->isEncrypted($data[$field])) {
                try {
                    $data[$field] = $this->encryptor->decrypt($data[$field]);
                } catch (\Throwable $e) {
                    error_log("[CustomerPII] Decryption failed for field '{$field}': " . $e->getMessage());
                    $data[$field] = '[DECRYPTION_ERROR]';
                }
            }
        }
        return $data;
    }
}
