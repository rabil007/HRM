<?php

namespace App\Support\CrewOperations;

use App\Models\EmployeeDeployment;

final class CrewOperationsDeploymentTrends
{
    /**
     * @return list<array{month: string, joins: int, disembarks: int}>
     */
    public static function lastSixMonths(int $companyId): array
    {
        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $joins = (int) EmployeeDeployment::query()
                ->where('company_id', $companyId)
                ->whereNotNull('joined_date')
                ->whereBetween('joined_date', [$start->toDateString(), $end->toDateString()])
                ->count();

            $disembarks = (int) EmployeeDeployment::query()
                ->where('company_id', $companyId)
                ->whereNotNull('disembarked_date')
                ->whereBetween('disembarked_date', [$start->toDateString(), $end->toDateString()])
                ->count();

            $points[] = [
                'month' => $month->format('M'),
                'joins' => $joins,
                'disembarks' => $disembarks,
            ];
        }

        return $points;
    }
}
