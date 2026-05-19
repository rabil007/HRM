<?php

namespace App\Support\Pagination;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

trait ResolvesPerPage
{
    /**
     * @param  list<int>  $allowed
     */
    protected function resolvePerPage(Request $request, int $default = 20, array $allowed = [10, 15, 20, 25, 30, 50, 100]): int
    {
        $perPage = (int) $request->query('per_page', $default);

        if (in_array($perPage, $allowed, true)) {
            return $perPage;
        }

        if (in_array($default, $allowed, true)) {
            return $default;
        }

        return $allowed[0] ?? $default;
    }

    /**
     * @return array{
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
