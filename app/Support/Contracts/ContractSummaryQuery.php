<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Models\EmployeeContract;

final class ContractSummaryQuery
{
    /**
     * @return array{
     *     total_contracts: int,
     *     active: int,
     *     ending_30: int,
     *     ending_60: int,
     *     ending_90: int,
     *     ended: int,
     *     no_contract_employees: int
     * }
     */
    public function forCompany(int $companyId): array
    {
        $today = now()->toDateString();
        $in30 = now()->addDays(30)->toDateString();
        $in60 = now()->addDays(60)->toDateString();
        $in90 = now()->addDays(90)->toDateString();

        $row = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as total_contracts')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'ended' THEN 1 ELSE 0 END) as ended")
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND end_date IS NOT NULL AND end_date >= ? AND end_date <= ? THEN 1 ELSE 0 END) as ending_30',
                ['active', $today, $in30],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND end_date IS NOT NULL AND end_date >= ? AND end_date <= ? THEN 1 ELSE 0 END) as ending_60',
                ['active', $today, $in60],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND end_date IS NOT NULL AND end_date >= ? AND end_date <= ? THEN 1 ELSE 0 END) as ending_90',
                ['active', $today, $in90],
            )
            ->first();

        $noContractCount = Employee::query()
            ->where('company_id', $companyId)
            ->whereDoesntHave('contracts')
            ->count();

        return [
            'total_contracts' => (int) ($row->total_contracts ?? 0),
            'active' => (int) ($row->active ?? 0),
            'ending_30' => (int) ($row->ending_30 ?? 0),
            'ending_60' => (int) ($row->ending_60 ?? 0),
            'ending_90' => (int) ($row->ending_90 ?? 0),
            'ended' => (int) ($row->ended ?? 0),
            'no_contract_employees' => $noContractCount,
        ];
    }
}
