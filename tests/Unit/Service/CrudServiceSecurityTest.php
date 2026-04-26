<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use InvalidArgumentException;
use OwnPay\Service\CrudService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security guards on CrudService — F1 from full_codebase_audit.md
 *
 * These tests confirm that CrudService rejects SQL-injection attempts in the
 * $condition / $select / $tableName parameters. The guards throw
 * InvalidArgumentException; callers cannot silently bypass them.
 */
final class CrudServiceSecurityTest extends TestCase
{
    #[DataProvider('unsafeConditions')]
    public function test_select_rejects_unsafe_condition(string $condition): void
    {
        $this->expectException(InvalidArgumentException::class);
        CrudService::select('items', $condition);
    }

    #[DataProvider('unsafeConditions')]
    public function test_count_rejects_unsafe_condition(string $condition): void
    {
        $this->expectException(InvalidArgumentException::class);
        CrudService::count('items', $condition);
    }

    public static function unsafeConditions(): array
    {
        return [
            'classic OR injection'        => ["WHERE id = '1' OR '1'='1'"],
            'tautology with double quote' => ['WHERE name = "x" OR 1=1'],
            'union select'                => ["WHERE id = 1 UNION SELECT password FROM users --"],
            'comment terminator'          => ["WHERE id = 1 -- and 1=2"],
            'block comment'               => ["WHERE id = 1 /* hide */ OR 1=1"],
            'stacked statement'           => ["WHERE id = 1; DROP TABLE users"],
            'NUL byte'                    => ["WHERE id = 1\0 OR 1=1"],
        ];
    }

    #[DataProvider('unsafeTableNames')]
    public function test_select_rejects_unsafe_table_name(string $tableName): void
    {
        $this->expectException(InvalidArgumentException::class);
        CrudService::select($tableName);
    }

    #[DataProvider('unsafeTableNames')]
    public function test_count_rejects_unsafe_table_name(string $tableName): void
    {
        $this->expectException(InvalidArgumentException::class);
        CrudService::count($tableName);
    }

    public static function unsafeTableNames(): array
    {
        return [
            'with backtick'        => ['items` --'],
            'with semicolon'       => ['items; DROP TABLE users'],
            'with space'           => ['items DROP'],
            'with hyphen'          => ['items-extra'],
            'starting with digit'  => ['1items'],
            'with quote'           => ["items'"],
            'empty'                => [''],
            'too long'             => [str_repeat('a', 65)],
        ];
    }

    public function test_safe_inputs_pass_validation(): void
    {
        // Validator must NOT throw on well-formed inputs. DB-layer errors
        // (no real DB in unit test) are acceptable; we only care about the guard.
        $this->expectNotToPerformAssertions();
        try {
            CrudService::select('items');
            CrudService::select('op_merchants', 'WHERE id = :id', '* FROM', [':id' => 1]);
            CrudService::count('op_transactions', 'WHERE merchant_id = :mid', [':mid' => 1]);
        } catch (InvalidArgumentException $e) {
            $this->fail("Safe input wrongly rejected: " . $e->getMessage());
        } catch (\Throwable $e) {
            // DB-layer failures are acceptable
        }
    }

    public function test_allowRawCondition_bypasses_guard(): void
    {
        // The opt-in flag allows fragments like ORDER BY clauses.
        $this->expectNotToPerformAssertions();
        try {
            CrudService::select(
                'op_transactions',
                'ORDER BY id DESC LIMIT 10',
                '* FROM',
                [],
                true  // allowRawCondition
            );
        } catch (InvalidArgumentException $e) {
            $this->fail("allowRawCondition flag failed to bypass guard: " . $e->getMessage());
        } catch (\Throwable $e) {
            // DB-layer failures are acceptable
        }
    }
}
