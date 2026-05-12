<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Pagination service â€” offset-based pagination with page metadata.
 */
final class PaginationService
{
    /**
     * Calculate pagination metadata.
     *
     * @return array{page: int, per_page: int, total: int, total_pages: int, offset: int, has_next: bool, has_prev: bool}
     */
    public static function calculate(int $page, int $perPage, int $total): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 200)); // Cap at 200
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
            'offset'      => $offset,
            'has_next'    => $page < $totalPages,
            'has_prev'    => $page > 1,
        ];
    }

    /**
     * Generate page URL.
     */
    public static function pageUrl(string $baseUrl, int $page, array $params = []): string
    {
        $params['page'] = $page;
        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Generate page range for UI rendering (e.g. [1,2,3,...,8,9,10]).
     * @return int[]
     */
    public static function pageRange(int $currentPage, int $totalPages, int $window = 2): array
    {
        if ($totalPages <= 1) {
            return [1];
        }

        $range = [];

        // Always include first page
        $range[] = 1;

        $start = max(2, $currentPage - $window);
        $end = min($totalPages - 1, $currentPage + $window);

        if ($start > 2) {
            $range[] = -1; // Ellipsis marker
        }

        for ($i = $start; $i <= $end; $i++) {
            $range[] = $i;
        }

        if ($end < $totalPages - 1) {
            $range[] = -1; // Ellipsis marker
        }

        // Always include last page
        if ($totalPages > 1) {
            $range[] = $totalPages;
        }

        return $range;
    }
}
