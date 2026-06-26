<?php
declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Event\EventManager;
use OwnPay\Repository\CustomerRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Security\PiiMasker;
use Ramsey\Uuid\Uuid;

/**
 * Service managing customer Personally Identifiable Information (PII).
 *
 * Handles encryption/decryption of sensitive fields at rest using AES-256-GCM
 * to align with PCI-DSS compliance requirements. Dispatches customer lifecycle events.
 */
final class CustomerPiiService
{
    /**
     * @var CustomerRepository Repository for customer records.
     */
    private CustomerRepository $customers;

    /**
     * @var FieldEncryptor Service handling AES-256-GCM field encryption.
     */
    private FieldEncryptor $encryptor;

    /**
     * @var EventManager Event dispatcher system.
     */
    private EventManager $events;

    /**
     * @var string[] PII fields requiring database-level encryption.
     */
    private const PII_FIELDS = ['email', 'phone', 'name', 'address'];

    /**
     * Constructs a new CustomerPiiService instance.
     *
     * @param CustomerRepository $customers The customer repository.
     * @param FieldEncryptor $encryptor The cryptographic field encryptor.
     * @param EventManager $events The event manager.
     */
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
     * Creates a new customer record with encrypted PII.
     *
     * Generates standard UUID identifiers and deterministic hashes for lookups.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param array<string, mixed> $data Customer input attributes.
     * @return array<string, mixed> The newly created customer data array.
     */
    public function create(int $merchantId, array $data): array
    {
        $encrypted = $this->encryptPii($data);

        $encrypted['uuid'] = Uuid::uuid4()->toString();

        $emailVal = $data['email'] ?? '';
        $encrypted['email_hash'] = is_string($emailVal) && $emailVal !== ''
            ? $this->encryptor->deterministicHash($emailVal)
            : '';
        $phoneVal = $data['phone'] ?? '';
        if (is_string($phoneVal) && $phoneVal !== '') {
            $encrypted['phone_hash'] = $this->encryptor->deterministicHash($phoneVal);
        }

        $repo = $this->customers->forTenant($merchantId);
        $id = $repo->createScoped($encrypted);
        $customer = $repo->findScoped((int) $id);
        if ($customer === null) {
            throw new \RuntimeException('Failed to retrieve newly created customer.');
        }

        $this->events->doAction('customer.created', $this->maskForEvent($customer));

        return $customer;
    }

    /**
     * Locates a customer record matching the provided email address.
     *
     * Performs a deterministic hash lookup to preserve index speed and privacy.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $email The target customer email address.
     * @return array<string, mixed>|null Customer record array if found; null otherwise.
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
     * Locates a customer record matching the provided phone number.
     *
     * Performs a deterministic hash lookup to preserve index speed and privacy.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $phone The target customer phone number.
     * @return array<string, mixed>|null Customer record array if found; null otherwise.
     */
    public function findByPhone(int $merchantId, string $phone): ?array
    {
        $hash = $this->encryptor->deterministicHash($phone);
        $customer = $this->customers->forTenant($merchantId)->findByPhoneHash($hash);

        if ($customer !== null) {
            return $this->decryptPii($customer);
        }
        return null;
    }

    /**
     * Finds a customer by contact identifier, auto-detecting phone vs email.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $identifier An email address or phone number identifier.
     * @return array<string, mixed>|null Customer record array if found; null otherwise.
     */
    public function findByContact(int $merchantId, string $identifier): ?array
    {
        if (str_contains($identifier, '@')) {
            return $this->findByEmail($merchantId, $identifier);
        }
        return $this->findByPhone($merchantId, $identifier);
    }

    /**
     * Retrieves a single customer record, fully decrypting PII.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $customerId Unique identifier of the customer.
     * @return array<string, mixed>|null Customer record array if found; null otherwise.
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
     * Updates an existing customer record's PII.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $customerId Unique identifier of the customer.
     * @param array<string, mixed> $data Updated customer attributes.
     * @return array<string, mixed> The updated customer record array.
     */
    public function update(int $merchantId, int $customerId, array $data): array
    {
        $encrypted = $this->encryptPii($data);

        $emailVal = $data['email'] ?? '';
        if (is_string($emailVal) && $emailVal !== '') {
            $encrypted['email_hash'] = $this->encryptor->deterministicHash($emailVal);
        }

        $phoneVal = $data['phone'] ?? '';
        if (is_string($phoneVal) && $phoneVal !== '') {
            $encrypted['phone_hash'] = $this->encryptor->deterministicHash($phoneVal);
        }

        $repo = $this->customers->forTenant($merchantId);
        $repo->updateScoped($customerId, $encrypted);
        $customer = $repo->findScoped($customerId);
        if ($customer === null) {
            throw new \RuntimeException('Failed to retrieve updated customer.');
        }

        $this->events->doAction('customer.updated', $this->maskForEvent($customer));

        return $customer;
    }

    /**
     * Performs a soft delete by clearing all PII fields and setting status.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $customerId Unique identifier of the customer to delete.
     * @return void
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
     * Lists customer records, returning masked PII structures.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $page Pagination page index.
     * @param int $perPage Pagination page size.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} Pagination payload.
     */
    public function list(int $merchantId, int $page = 1, int $perPage = 50): array
    {
        $result = $this->customers->forTenant($merchantId)->paginateScoped($page, $perPage);
        $items = $result['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as &$customer) {
                if (is_array($customer)) {
                    $customerMap = [];
                    foreach ($customer as $k => $v) {
                        $customerMap[(string)$k] = $v;
                    }
                    $decrypted = $this->decryptPii($customerMap);
                    $emailVal = $decrypted['email'] ?? '';
                    $phoneVal = $decrypted['phone'] ?? '';
                    $decrypted['email_masked'] = PiiMasker::maskEmail(is_string($emailVal) ? $emailVal : '');
                    $decrypted['phone_masked'] = PiiMasker::maskPhone(is_string($phoneVal) ? $phoneVal : '');
                    $customer = $decrypted;
                }
            }
            $result['items'] = $items;
        }
        /** @var array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} $result */
        return $result;
    }

    /**
     * Encrypts plain PII fields within a data array.
     *
     * @param array<string, mixed> $data Plain customer attributes.
     * @return array<string, mixed> Data array with PII encrypted and raw fields removed.
     */
    private function encryptPii(array $data): array
    {
        foreach (self::PII_FIELDS as $field) {
            $val = $data[$field] ?? null;
            if (is_string($val) && $val !== '') {
                $data["{$field}_enc"] = $this->encryptor->encrypt($val);
                unset($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Decrypts encrypted PII fields within a customer record.
     *
     * @param array<string, mixed> $customer Customer database record.
     * @return array<string, mixed> Customer record with decrypted plain fields.
     */
    private function decryptPii(array $customer): array
    {
        foreach (self::PII_FIELDS as $field) {
            $encVal = $customer["{$field}_enc"] ?? null;
            if (is_string($encVal) && $encVal !== '') {
                try {
                    $customer[$field] = $this->encryptor->decrypt($encVal);
                } catch (\Throwable) {
                    $customer[$field] = '[decryption failed]';
                }
            }
        }
        return $customer;
    }

    /**
     * Masks PII properties before dispatching lifecycle hook events.
     *
     * @param array<string, mixed> $customer Decrypted customer record.
     * @return array<string, mixed> Masked customer record for hooks.
     */
    private function maskForEvent(array $customer): array
    {
        $emailVal = $customer['email'] ?? $customer['email_enc'] ?? '';
        $phoneVal = $customer['phone'] ?? $customer['phone_enc'] ?? '';
        $customer['email'] = PiiMasker::maskEmail(is_string($emailVal) ? $emailVal : '');
        $customer['phone'] = PiiMasker::maskPhone(is_string($phoneVal) ? $phoneVal : '');
        unset($customer['email_enc'], $customer['phone_enc'], $customer['name_enc']);
        return $customer;
    }
}
