<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Security\FieldEncryptor;

final class MerchantUserRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_merchant_users';
    private ?FieldEncryptor $encryptor;

    public function __construct(\OwnPay\Core\Database $db, ?FieldEncryptor $encryptor = null)
    {
        parent::__construct($db);
        $this->encryptor = $encryptor;
    }
    protected array $fillable = [
        'merchant_id', 'role_id', 'name', 'email', 'password_hash',
        'phone', 'avatar_path', 'totp_secret_enc', 'two_factor_enabled',
        'last_login_at', 'last_login_ip', 'status',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    /**
     * Find active user by email (for auth).
     */
    public function findActiveByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE email = :email AND status = 'active' LIMIT 1",
            ['email' => $email]
        );
    }

    /**
     * Find active user by email OR username (for login form).
     */
    public function findActiveByLogin(string $login): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE (email = :login_email OR username = :login_user) AND status = 'active' LIMIT 1",
            ['login_email' => $login, 'login_user' => $login]
        );
    }

    public function updateLastLogin(int $userId, string $ip): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_login_at = NOW(6), last_login_ip = :ip WHERE id = :id",
            ['ip' => $ip, 'id' => $userId]
        );
    }

    /**
     * Find user by ID (for profile page, 2FA setup).
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, name, email, two_factor_enabled, totp_secret_enc, merchant_id, role_id, status, created_at
             FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Update user profile (name + email).
     */
    public function updateProfile(int $id, string $name, string $email): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET name = :n, email = :e WHERE id = :id",
            ['n' => $name, 'e' => $email, 'id' => $id]
        );
    }

    /**
     * Update password hash.
     */
    public function updatePassword(int $id, string $hash): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET password_hash = :p WHERE id = :id",
            ['p' => $hash, 'id' => $id]
        );
    }

    /**
     * Get password hash for verification.
     */
    public function getPasswordHash(int $id): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT password_hash FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
        return $row['password_hash'] ?? null;
    }

    /**
     * Paginated staff listing with role names.
     */
    public function listStaffPaginated(int $merchantId, int $limit, int $offset): array
    {
        $items = $this->db->fetchAll(
            "SELECT u.*, r.name as role_name
             FROM {$this->table} u
             LEFT JOIN op_roles r ON r.id = u.role_id
             WHERE u.merchant_id = :mid
             ORDER BY u.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            ['mid' => $merchantId]
        );
        $total = $this->db->count($this->table, 'merchant_id = :mid', ['mid' => $merchantId]);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * List all staff (global view for superadmin).
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
     * List staff for specific merchant.
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
     * Find staff by ID (optionally scoped to merchant).
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
     * Create staff user.
     * AUD-05 FIX: Accept optional $roleId so callers can pass resolved role.
     */
    public function createStaff(int $merchantId, string $name, string $email, string $passwordHash, ?int $roleId = null): string
    {
        // Use provided roleId, or resolve Staff role for this merchant
        if ($roleId === null) {
            $role = $this->db->fetchOne(
                "SELECT id FROM op_roles WHERE merchant_id = :mid AND slug = 'staff' LIMIT 1",
                ['mid' => $merchantId]
            );
            $roleId = $role ? (int) $role['id'] : 1;
        }

        return $this->create([
            'merchant_id'   => $merchantId,
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $passwordHash,
            'role_id'       => $roleId,
            'status'        => 'active',
        ]);
    }

    /**
     * Update staff fields dynamically.
     */
    public function updateStaff(int $id, array $fields, ?int $merchantId = null): void
    {
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
     * Delete non-superadmin staff.
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

    // ─── 2FA Methods ─────────────────────────────────────────────

    /**
     * Get TOTP secret for user.
     */
    public function getTotpSecret(int $id): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT totp_secret_enc FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
        $raw = $row['totp_secret_enc'] ?? null;
        if ($raw === null) {
            return null;
        }
        return $this->encryptor ? $this->encryptor->decrypt($raw) : $raw;
    }

    /**
     * Store pending TOTP secret.
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
     * Enable TOTP (after code verification).
     */
    public function enableTotp(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET two_factor_enabled = 1 WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Disable TOTP + clear secret.
     */
    public function disableTotp(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET two_factor_enabled = 0, totp_secret_enc = NULL WHERE id = :id",
            ['id' => $id]
        );
    }
}
