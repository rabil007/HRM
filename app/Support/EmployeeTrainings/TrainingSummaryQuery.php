<?php

namespace App\Support\EmployeeTrainings;

use App\Models\EmployeeTraining;

final class TrainingSummaryQuery
{
    /**
     * @return array{
     *     total: int,
     *     expired: int,
     *     expiring_30: int,
     *     expiring_15: int,
     *     expiring_7: int
     * }
     */
    public function forCompany(int $companyId, ?TrainingDirectoryFilters $filters = null): array
    {
        $today = now()->toDateString();
        $in7 = now()->addDays(7)->toDateString();
        $in15 = now()->addDays(15)->toDateString();
        $in30 = now()->addDays(30)->toDateString();

        $query = $filters !== null
            ? (new TrainingDirectoryQuery($companyId, $filters))->summaryQuery()
            : EmployeeTraining::query()->where('employee_trainings.company_id', $companyId);

        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < ? THEN 1 ELSE 0 END) as expired',
                [$today],
            )
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_30',
                [$today, $in30],
            )
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_15',
                [$today, $in15],
            )
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_7',
                [$today, $in7],
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'expiring_30' => (int) ($row->expiring_30 ?? 0),
            'expiring_15' => (int) ($row->expiring_15 ?? 0),
            'expiring_7' => (int) ($row->expiring_7 ?? 0),
        ];
    }
}
