<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class RoleRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_roles';
    protected array $fillable = ['merchant_id', 'name', 'slug', 'description', 'is_system'];

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :s AND merchant_id = :mid LIMIT 1",
            ['s' => $slug, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Get permissions for role.
     * @return string[] Permission slugs
     */
    public function getPermissions(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.slug FROM op_role_permissions rp
             JOIN op_permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid",
            ['rid' => $roleId]
        );
        return array_column($rows, 'slug');
    }

    /**
     * Sync permissions for role (replace all).
     * @param int[] $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->transaction(function () use ($roleId, $permissionIds) {
            $this->db->delete("DELETE FROM op_role_permissions WHERE role_id = :rid", ['rid' => $roleId]);
            foreach ($permissionIds as $pid) {
                $this->db->insert(
                    "INSERT INTO op_role_permissions (role_id, permission_id) VALUES (:rid, :pid)",
                    ['rid' => $roleId, 'pid' => $pid]
                );
            }
        });
    }
}
