<?php

declare(strict_types=1);

namespace OwnPay\Service;

final class PaginationService
{
    /**
     * Calculate pagination parameters from request values.
     *
     * @param  string|int $rawPage      The raw page value from the request (e.g. $request->post('page', '1'))
     * @param  string|int $rawShowLimit The raw show_limit value from the request (e.g. $request->post('show_limit'))
     * @param  int        $fallback     Fallback per-page value when show_limit is empty (default 999999)
     * @return array{page: int, perPage: int, offset: int, isAll: bool}
     */
    public static function resolve($rawPage = '1', $rawShowLimit = '', int $fallback = 999999): array
    {
        $page    = max(1, (int) $rawPage);
        $isAll   = ($rawShowLimit === 'all');
        $perPage = ($rawShowLimit === '' || $rawShowLimit === null)
            ? $fallback
            : ($isAll ? $fallback : (int) $rawShowLimit);
        $offset  = ($page - 1) * $perPage;

        return [
            'page'    => $page,
            'perPage' => $perPage,
            'offset'  => $offset,
            'isAll'   => $isAll,
        ];
    }

    /**
     * Build pagination HTML and datatable info string.
     *
     * Returns the exact same markup the controllers previously rendered inline:
     * prev/next SVG buttons, numbered page buttons, and a "Showing X to Y of Z entries" line.
     *
     * @return array{pagination: string, datatableInfo: string}
     */
    public static function render(int $currentPage, int $totalRecords, int $perPage, int $offset): array
    {
        $totalPages = (int) ceil($totalRecords / max(1, $perPage));

        $pagination = '<ul class="pagination m-0 ms-auto">';

        // Prev button
        $pagination .= '<li class="page-item' . ($currentPage <= 1 ? ' disabled' : '') . '">
                        <button class="page-link" ' . ($currentPage > 1 ? 'data-page="' . ($currentPage - 1) . '"' : '') . '>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M15 6l-6 6l6 6"></path>
                            </svg>
                        </button>
                    </li>';

        // Page numbers
        for ($i = 1; $i <= $totalPages; $i++) {
            $pagination .= '<li class="page-item' . ($i == $currentPage ? ' active' : '') . '">
                            <button class="page-link" data-page="' . $i . '">' . $i . '</button>
                        </li>';
        }

        // Next button
        $pagination .= '<li class="page-item' . ($currentPage >= $totalPages ? ' disabled' : '') . '">
                        <button class="page-link" ' . ($currentPage < $totalPages ? 'data-page="' . ($currentPage + 1) . '"' : '') . '>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M9 6l6 6l-6 6"></path>
                            </svg>
                        </button>
                    </li>';

        $pagination .= '</ul>';

        $start = ($offset + 1);
        $end   = min($offset + $perPage, $totalRecords);

        $datatableInfo = "Showing <strong>$start to $end</strong> of <strong>$totalRecords entries</strong>";

        return [
            'pagination'    => $pagination,
            'datatableInfo' => $datatableInfo,
        ];
    }
}
