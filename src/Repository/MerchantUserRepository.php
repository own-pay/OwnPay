<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Security\FieldEncryptor;

/**
 * Repository layer for brand-specific administrators and staff users (`op_merchant_users` table).
 *
 * Scopes queries and operations to specific merchants (brands/stores) using the TenantScope trait.
 * Manages administrative credentials, security configurations (TOTP 2FA), staff management,
 * and profile adjustments.
 *
 * @package OwnPay\Repository
 */
final class MerchantUserRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_merchant_users';

    /**
     * @var FieldEncryptor|null Utility service to encrypt/decrypt sensitive fields like 2FA secrets.
     */
    private ?FieldEncryptor $encryptor;

    /**
     * MerchantUserRepository constructor.
     *
     * @param \OwnPay\Core\Database $db Core database connection adapter.
     * @param FieldEncryptor|null $encryptor Field encryptor instance for secure operations.
     */
    public function __construct(\OwnPay\Core\Database $db, ?FieldEncryptor $encryptor = null)
    {
        parent::__construct($db);
        $this->encryptor = $encryptor;
    }

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'role_id', 'name', 'email', 'language', 'password_hash',
        'phone', 'avatar_path', 'totp_secret_enc', 'two_factor_enabled',
        'last_login_at', 'last_login_ip', 'status',
    ];

    /**
     * Finds a user record by their email address.
     *
     * @param string $email The user's email address.
     * @return array<string, mixed>|null The user database record, or null if not found.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    /**
     * Finds an active user record by their email address for authentication checks.
     *
     * @param string $email The user's email address.
     * @return array<string, mixed>|null The active user database record, or null if not found.
     */
    public function findActiveByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE email = :email AND status = 'active' LIMIT 1",
            ['email' => $email]
        );
    }

    /**
     * Finds an active user by their email or username login identifier.
     *
     * Used directly in authentication/login workflows.
     *
     * @param string $login The email address or username string.
     * @return array<string, mixed>|null The matching active user record, or null if not found.
     */
    public function findActiveByLogin(string $login): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE (email = :login_email OR username = :login_user) AND status = 'active' LIMIT 1",
            ['login_email' => $login, 'login_user' => $login]
        );
    }

    /**
     * Updates a user's last login timestamp and IP address.
     *
     * @param int $userId The primary key ID of the user.
     * @param string $ip The request source IP address.
     * @return void
     */
    public function updateLastLogin(int $userId, string $ip): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_login_at = NOW(6), last_login_ip = :ip WHERE id = :id",
            ['ip' => $ip, 'id' => $userId]
        );
    }

    /**
     * Finds a user record by ID, selecting only non-sensitive columns.
     *
     * Commonly used for profile management and 2FA configuration screens.
     *
     * @param int $id The primary key ID of the user.
     * @return array<string, mixed>|null The filtered user record, or null if not found.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, name, email, language, two_factor_enabled, totp_secret_enc, merchant_id, role_id, status, created_at
             FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Updates standard profile details for a user.
     *
     * @param int $id The user's primary key ID.
     * @param string $name The updated display name.
     * @param string $email The updated email address.
     * @param string|null $language The user preferred language code.
     * @return void
     */
    public function updateProfile(int $id, string $name, string $email, ?string $language = null): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET name = :n, email = :e, language = :lang WHERE id = :id",
            ['n' => $name, 'e' => $email, 'lang' => $language, 'id' => $id]
        );
    }

    /**
     * Updates the password hash for a user.
     *
     * @param int $id The user's primary key ID.
     * @param string $hash The raw Argon2id password hash.
     * @return void
     */
    public function updatePassword(int $id, string $hash): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET password_hash = :p WHERE id = :id",
            ['p' => $hash, 'id' => $id]
        );
    }

    /**
     * Retrieves the password hash for a user to perform authentication verification.
     *
     * @param int $id The user's primary key ID.
     * @return string|null The password hash, or null if the user does not exist.
     */
    public function getPasswordHash(int $id): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT password_hash FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
        $hash = $row['password_hash'] ?? null;
        return is_string($hash) ? $hash : null;
    }

    /**
     * Returns a paginated list of staff users for a specific merchant, joining role labels.
     *
     * @param int $merchantId The merchant brand ID.
     * @param int $limit Total records to return.
     * @param int $offset Offset sequence number.
     * @return array{items: array<int, array<string, mixed>>, total: int} A structure containing the paginated items and total count.
     */
    public function listStaffPaginated(int $merchantId, int $limit, int $offset): array
    {
        $items = $this->db->fetchAll(
            "SELECT u.*, r.name as role_name
             FROM {$this->table} u
             LEFT JOIN op_roles r ON r.id = u.role_id
             WHERE u.merchant_id = :mid
             ORDER BY u.created_at DESC
             LIMIT :lim OFFSET :off",
            ['mid' => $merchantId, 'lim' => $limit, 'off' => $offset]
        );
        $total = $this->db->count($this->table, 'merchant_id = :mid', ['mid' => $merchantId]);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Retrieves all staff users across all brands for superadmin views.
     *
     * @return array<int, array<string, mixed>> List of all staff records with brand and role names.
     */
    public function listAllStaff(): array
    {
        return $this->db->fetchAll(
            "SELECT u.*, m.name as brand_name, r.name as role
             FROM {$this->table} u
             LEFT JOIN op_merchants m ON u.merchant_id = m.id
             LEFT JOIN op_roles r ON u.role_id = r.id
             ORDER BY u.name"
        );
    }

    /**
     * Lists all staff members associated with a specific merchant.
     *
     * @param int $merchantId The merchant brand ID.
     * @return array<int, array<string, mixed>> List of matching staff records.
     */
    public function listStaffForMerchant(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT u.*, r.name as role
             FROM {$this->table} u
             LEFT JOIN op_roles r ON u.role_id = r.id
             WHERE u.merchant_id = :mid
             ORDER BY u.name",
            ['mid' => $merchantId]
        );
    }

    /**
     * Finds a staff user record by ID, optionally verifying merchant context.
     *
     * @param int $id The user ID.
     * @param int|null $merchantId Optional merchant context scope check.
     * @return array<string, mixed>|null The staff user record, or null if not found.
     */
    public function findStaff(int $id, ?int $merchantId = null): ?array
    {
        if ($merchantId === null) {
            return $this->find($id);
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE id = :id AND merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $merchantId]
        );
    }

    /**
     * Creates a new staff member for a brand.
     *
     * @param int $merchantId The merchant ID to assign the staff member to.
     * @param string $name The display name.
     * @param string $email The contact/login email.
     * @param string $passwordHash The hashed password.
     * @param int|null $roleId Optional role ID override (defaults to finding 'staff' slug or role ID 1).
     * @return string The primary key ID of the newly created staff member.
     */
    public function createStaff(
        int $merchantId,
        string $name,
        string $email,
        string $passwordHash,
        ?int $roleId = null,
        ?string $username = null,
        ?string $phone = null,
        string $status = 'active',
        ?string $avatarPath = null
    ): string {
        // Use provided roleId, or resolve Staff role for this merchant
        if ($roleId === null) {
            $role = $this->db->fetchOne(
                "SELECT id FROM op_roles WHERE merchant_id = :mid AND slug = 'staff' LIMIT 1",
                ['mid' => $merchantId]
            );
            $roleId = ($role && isset($role['id']) && is_scalar($role['id'])) ? (int) $role['id'] : 1;
        }

        return $this->create([
            'merchant_id'   => $merchantId,
            'name'          => $name,
            'email'         => $email,
            'username'      => $username,
            'password_hash' => $passwordHash,
            'role_id'       => $roleId,
            'phone'         => $phone,
            'status'        => $status,
            'avatar_path'   => $avatarPath,
        ]);
    }

    /**
     * Updates specific fields of a staff user.
     *
     * Validates fields against an allowed column whitelist to prevent SQL injection 
     * and unauthorized privilege escalation via raw parameter arrays.
     *
     * @param int $id The target user ID.
     * @param array<string, mixed> $fields Key-value pairs of parameters to update.
     * @param int|null $merchantId Optional merchant context scope verification.
     * @return void
     */
    public function updateStaff(int $id, array $fields, ?int $merchantId = null): void
    {
        // Only allow updating these safe columns
        $allowed = ['name', 'email', 'username', 'phone', 'role_id', 'status', 'avatar_path', 'password_hash'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if (empty($fields)) {
            return;
        }

        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
        $fields['id'] = $id;

        if ($merchantId !== null) {
            $fields['mid'] = $merchantId;
            $this->db->execute("UPDATE {$this->table} SET {$sets} WHERE id = :id AND merchant_id = :mid", $fields);
        } else {
            $this->db->execute("UPDATE {$this->table} SET {$sets} WHERE id = :id", $fields);
        }
    }

    /**
     * Deletes a staff member (excluding superadmin users to protect administrative system integrity).
     *
     * @param int $id The staff user ID.
     * @param int|null $merchantId Optional merchant context scope verification.
     * @return void
     */
    public function deleteStaff(int $id, ?int $merchantId = null): void
    {
        if ($merchantId !== null) {
            $this->db->execute(
                "DELETE FROM {$this->table} WHERE id = :id AND merchant_id = :mid AND is_superadmin = 0",
                ['id' => $id, 'mid' => $merchantId]
            );
        } else {
            $this->db->execute(
                "DELETE FROM {$this->table} WHERE id = :id AND is_superadmin = 0",
                ['id' => $id]
            );
        }
    }

    // --- 2FA Methods
    /**
     * Retrieves the decrypted TOTP secret for a user.
     *
     * @param int $id The user's primary key ID.
     * @return string|null The decrypted TOTP secret string, or null if not configured.
     */
    public function getTotpSecret(int $id): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT totp_secret_enc FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
        $raw = $row['totp_secret_enc'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        return $this->encryptor ? $this->encryptor->decrypt($raw) : $raw;
    }

    /**
     * Encrypts and stores the TOTP secret key for a user.
     *
     * @param int $id The user's primary key ID.
     * @param string $secret The plaintext TOTP secret key.
     * @return void
     */
    public function setTotpSecret(int $id, string $secret): void
    {
        $encrypted = $this->encryptor ? $this->encryptor->encrypt($secret) : $secret;
        $this->db->execute(
            "UPDATE {$this->table} SET totp_secret_enc = :s WHERE id = :id",
            ['s' => $encrypted, 'id' => $id]
        );
    }

    /**
     * Enables 2FA TOTP enforcement for the user.
     *
     * @param int $id The user's primary key ID.
     * @return void
     */
    public function enableTotp(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET two_factor_enabled = 1 WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Disables 2FA TOTP enforcement and clears the stored secret.
     *
     * @param int $id The user's primary key ID.
     * @return void
     */
    public function disableTotp(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET two_factor_enabled = 0, totp_secret_enc = NULL WHERE id = :id",
            ['id' => $id]
        );
    }
}

