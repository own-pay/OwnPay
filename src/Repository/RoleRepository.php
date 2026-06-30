<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for staff roles and access control permissions (`op_roles` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Handles permission synchronizations and permission listings.
 *
 * @package OwnPay\Repository
 */
final class RoleRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_roles';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = ['merchant_id', 'name', 'slug', 'description', 'is_system'];

    /**
     * Finds a role record by its unique slug identifier under the active tenant context.
     *
     * @param string $slug Unique role slug name.
     * @return array<string, mixed>|null The role database record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :s AND merchant_id = :mid LIMIT 1",
            ['s' => $slug, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Retrieves all permission slugs associated with a role.
     *
     * @param int $roleId Primary key ID of the role.
     * @return list<string> List of permissions slugs.
     */
    public function getPermissions(int $roleId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.slug FROM op_role_permissions rp
             JOIN op_permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid",
            ['rid' => $roleId]
        );
        $result = [];
        foreach ($rows as $row) {
            $slug = $row['slug'] ?? '';
            if (is_string($slug) && $slug !== '') {
                $result[] = $slug;
            }
        }
        return $result;
    }

    /**
     * Synchronizes role permissions by replacing the existing association mapping.
     *
     * Performs atomic delete and insert statements inside a database transaction block.
     *
     * @param int $roleId Primary key ID of the role.
     * @param list<int> $permissionIds List of permission primary key IDs to map to the role.
     * @return void
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

