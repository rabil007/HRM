<?php

namespace App\Support\SeaServices;

use App\Models\EmployeeSeaService;
use App\Models\User;

final class SeaServiceSummaryQuery
{
    /**
     * @return array{
     *     total: int,
     *     active: int
     * }
     */
    public function forCompany(int $companyId, ?SeaServiceDirectoryFilters $filters = null, ?User $user = null): array
    {
        $query = $filters !== null
            ? (new SeaServiceDirectoryQuery($companyId, $filters, $user))->summaryQuery()
            : EmployeeSeaService::query()->where('employee_sea_services.company_id', $companyId);

        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN end_date IS NULL THEN 1 ELSE 0 END) as active')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
        ];
    }
}
