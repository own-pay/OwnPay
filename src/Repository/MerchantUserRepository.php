<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class MerchantUserRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_merchant_users';
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

    public function updateLastLogin(int $userId, string $ip): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_login_at = NOW(6), last_login_ip = :ip WHERE id = :id",
            ['ip' => $ip, 'id' => $userId]
        );
    }
}
