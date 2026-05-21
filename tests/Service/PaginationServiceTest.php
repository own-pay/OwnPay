<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use OwnPay\Service\System\PaginationService;

class PaginationServiceTest extends TestCase
{
    public function testCalculatePaginationMetadata(): void
    {
        $result = PaginationService::calculate(2, 10, 45);

        $this->assertEquals(2, $result['page']);
        $this->assertEquals(10, $result['per_page']);
        $this->assertEquals(45, $result['total']);
        $this->assertEquals(45, $result['total_items']);
        $this->assertEquals(5, $result['total_pages']);
        $this->assertEquals(10, $result['offset']);
        $this->assertTrue($result['has_next']);
        $this->assertTrue($result['has_prev']);
    }

    public function testCalculateFirstPageBounds(): void
    {
        $result = PaginationService::calculate(1, 10, 5);

        $this->assertEquals(1, $result['page']);
        $this->assertEquals(5, $result['total']);
        $this->assertEquals(5, $result['total_items']);
        $this->assertEquals(1, $result['total_pages']);
        $this->assertEquals(0, $result['offset']);
        $this->assertFalse($result['has_next']);
        $this->assertFalse($result['has_prev']);
    }

    public function testPageUrlGeneration(): void
    {
        $url = PaginationService::pageUrl('https://example.com/admin/transactions', 3, ['q' => 'test', 'status' => 'completed']);
        $this->assertEquals('https://example.com/admin/transactions?q=test&status=completed&page=3', $url);
    }

    public function testPageRange(): void
    {
        $range = PaginationService::pageRange(5, 10, 1);
        $this->assertEquals([1, -1, 4, 5, 6, -1, 10], $range);
    }
}
