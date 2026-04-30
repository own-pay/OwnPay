<?php
declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Event\EventManager;
use OwnPay\Repository\CustomerRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Security\PiiMasker;

/**
 * Customer PII service — encrypted CRUD with PII masking.
 *
 * Per PCI-DSS: all PII encrypted at rest (AES-256-GCM).
 * Fires: customer.created, customer.updated, customer.deleted
 */
final class CustomerPiiService
{
    private CustomerRepository $customers;
    private FieldEncryptor $encryptor;
    private EventManager $events;

    /** PII fields that must be encrypted */
    private const PII_FIELDS = ['email', 'phone', 'name', 'address'];

    public function __construct(
        CustomerRepository $customers,
        FieldEncryptor $encryptor,
        EventManager $events
    ) {
        $this->customers = $customers;
        $this->encryptor = $encryptor;
        $this->events = $events;
    }

    /**
     * Create customer with encrypted PII.
     */
    public function create(int $merchantId, array $data): array
    {
        $encrypted = $this->encryptPii($data);

        // Generate deterministic hash for email lookup
        if (!empty($data['email'])) {
            $encrypted['email_hash'] = $this->encryptor->deterministicHash($data['email']);
        }

        $repo = $this->customers->forTenant($merchantId);
        $id = $repo->createScoped($encrypted);
        $customer = $repo->findScoped((int) $id);

        $this->events->doAction('customer.created', $this->maskForEvent($customer));

        return $customer;
    }

    /**
     * Find customer by email (via deterministic hash).
     */
    public function findByEmail(int $merchantId, string $email): ?array
    {
        $hash = $this->encryptor->deterministicHash($email);
        $customer = $this->customers->forTenant($merchantId)->findByEmailHash($hash);

        if ($customer !== null) {
            return $this->decryptPii($customer);
        }
        return null;
    }

    /**
     * Get customer with decrypted PII.
     */
    public function get(int $merchantId, int $customerId): ?array
    {
        $customer = $this->customers->forTenant($merchantId)->findScoped($customerId);
        if ($customer === null) {
            return null;
        }
        return $this->decryptPii($customer);
    }

    /**
     * Update customer PII.
     */
    public function update(int $merchantId, int $customerId, array $data): array
    {
        $encrypted = $this->encryptPii($data);

        if (!empty($data['email'])) {
            $encrypted['email_hash'] = $this->encryptor->deterministicHash($data['email']);
        }

        $repo = $this->customers->forTenant($merchantId);
        $repo->updateScoped($customerId, $encrypted);
        $customer = $repo->findScoped($customerId);

        $this->events->doAction('customer.updated', $this->maskForEvent($customer));

        return $customer;
    }

    /**
     * Delete customer (soft delete — zero PII fields).
     */
    public function delete(int $merchantId, int $customerId): void
    {
        $repo = $this->customers->forTenant($merchantId);
        $repo->updateScoped($customerId, [
            'email_enc'  => null,
            'phone_enc'  => null,
            'name_enc'   => null,
            'email_hash' => null,
            'status'     => 'deleted',
        ]);

        $this->events->doAction('customer.deleted', $merchantId, $customerId);
    }

    /**
     * List customers (PII masked for list view).
     */
    public function list(int $merchantId, int $page = 1, int $perPage = 50): array
    {
        $result = $this->customers->forTenant($merchantId)->paginateScoped($page, $perPage);
        foreach ($result['items'] as &$customer) {
            $customer = $this->decryptPii($customer);
            $customer['email_masked'] = PiiMasker::maskEmail($customer['email'] ?? '');
            $customer['phone_masked'] = PiiMasker::maskPhone($customer['phone'] ?? '');
        }
        return $result;
    }

    private function encryptPii(array $data): array
    {
        foreach (self::PII_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $data["{$field}_enc"] = $this->encryptor->encrypt($data[$field]);
                unset($data[$field]);
            }
        }
        return $data;
    }

    private function decryptPii(array $customer): array
    {
        foreach (self::PII_FIELDS as $field) {
            if (!empty($customer["{$field}_enc"])) {
                try {
                    $customer[$field] = $this->encryptor->decrypt($customer["{$field}_enc"]);
                } catch (\Throwable) {
                    $customer[$field] = '[decryption failed]';
                }
            }
        }
        return $customer;
    }

    /**
     * Mask PII for event dispatch (plugins should not receive raw PII).
     */
    private function maskForEvent(array $customer): array
    {
        $customer['email'] = PiiMasker::maskEmail($customer['email'] ?? $customer['email_enc'] ?? '');
        $customer['phone'] = PiiMasker::maskPhone($customer['phone'] ?? $customer['phone_enc'] ?? '');
        unset($customer['email_enc'], $customer['phone_enc'], $customer['name_enc']);
        return $customer;
    }
}
