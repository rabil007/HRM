<?php

namespace App\Support\SeaServices;

use App\Models\EmployeeSeaService;

final class SeaServiceSummaryQuery
{
    /**
     * @return array{
     *     total: int,
     *     offshore: int,
     *     shore: int,
     *     active: int
     * }
     */
    public function forCompany(int $companyId, ?SeaServiceDirectoryFilters $filters = null): array
    {
        $query = $filters !== null
            ? (new SeaServiceDirectoryQuery($companyId, $filters))->summaryQuery()
            : EmployeeSeaService::query()->where('employee_sea_services.company_id', $companyId);

        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_offshore = 1 THEN 1 ELSE 0 END) as offshore')
            ->selectRaw('SUM(CASE WHEN is_offshore = 0 THEN 1 ELSE 0 END) as shore')
            ->selectRaw('SUM(CASE WHEN end_date IS NULL THEN 1 ELSE 0 END) as active')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'offshore' => (int) ($row->offshore ?? 0),
            'shore' => (int) ($row->shore ?? 0),
            'active' => (int) ($row->active ?? 0),
        ];
    }
}
