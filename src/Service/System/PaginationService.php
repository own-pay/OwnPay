<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Service orchestrating offset-based pagination and navigation metadata compilation.
 *
 * Computes offset boundaries, limits, total items, and page range selectors
 * for UI component presentation.
 */
final class PaginationService
{
    /**
     * Calculates page offsets, total pages, and navigation states.
     *
     * Limits the maximum items per page to 200 items to protect system memory resources.
     *
     * @param int $page Requested page index.
     * @param int $perPage Count of items per page.
     * @param int $total Total items in target query dataset.
     * @return array{page: int, per_page: int, total: int, total_items: int, total_pages: int, offset: int, has_next: bool, has_prev: bool} The paginated metadata result.
     */
    public static function calculate(int $page, int $perPage, int $total): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 200)); // Cap at 200 elements to prevent memory abuse ;)
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_items' => $total,
            'total_pages' => $totalPages,
            'offset'      => $offset,
            'has_next'    => $page < $totalPages,
            'has_prev'    => $page > 1 /** @phpstan-ignore greater.alwaysTrue */,
        ];
    }

    /**
     * Generates a fully formatted URL targeting a specific page index.
     *
     * @param string $baseUrl Base path string.
     * @param int $page Target page index.
     * @param array<string, mixed> $params Query parameters.
     * @return string Fully qualified page navigation URL.
     */
    public static function pageUrl(string $baseUrl, int $page, array $params = []): string
    {
        $params['page'] = $page;
        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Generates a list of page number markers (with ellipsis indicators) for UI rendering.
     *
     * Ellipsis indicators are represented by negative values (e.g. -1).
     *
     * @param int $currentPage The active page index.
     * @param int $totalPages Total pages.
     * @param int $window Count of neighbouring page buttons to show around the current page (defaults to 2).
     * @return int[] Numeric list containing page indexes and -1 ellipsis indicators.
     */
    public static function pageRange(int $currentPage, int $totalPages, int $window = 2): array
    {
        if ($totalPages <= 1) {
            return [1];
        }

        $range = [];

        // Always include the first page index
        $range[] = 1;

        $start = max(2, $currentPage - $window);
        $end = min($totalPages - 1, $currentPage + $window);

        if ($start > 2) {
            $range[] = -1; // Ellipsis indicator
        }

        for ($i = $start; $i <= $end; $i++) {
            $range[] = $i;
        }

        if ($end < $totalPages - 1) {
            $range[] = -1; // Ellipsis indicator
        }

        // Always include the last page index
        if ($totalPages > 1 /** @phpstan-ignore greater.alwaysTrue */) {
            $range[] = $totalPages;
        }

        return $range;
    }
}
