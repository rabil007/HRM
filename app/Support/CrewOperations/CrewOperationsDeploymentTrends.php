<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignmentPhase;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class CrewOperationsDeploymentTrends
{
    /**
     * @return list<array{month: string, joins: int, disembarks: int}>
     */
    public static function lastSixMonths(int $companyId, ?User $user = null): array
    {
        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $joinsQuery = CrewAssignmentPhase::query()
                ->where('company_id', $companyId)
                ->where('phase_code', CrewPhaseCode::OnVessel)
                ->whereNotNull('actual_start_at')
                ->whereBetween('actual_start_at', [$start, $end]);

            $disembarksQuery = CrewAssignmentPhase::query()
                ->where('company_id', $companyId)
                ->where('phase_code', CrewPhaseCode::OnVessel)
                ->whereNotNull('actual_end_at')
                ->whereBetween('actual_end_at', [$start, $end]);

            if ($user !== null) {
                EmployeeVisibilityScope::whereHas($joinsQuery, $user, $companyId, 'assignment.employee');
                EmployeeVisibilityScope::whereHas($disembarksQuery, $user, $companyId, 'assignment.employee');
            }

            $joins = (int) $joinsQuery->count();
            $disembarks = (int) $disembarksQuery->count();

            $points[] = [
                'month' => $month->format('M'),
                'joins' => $joins,
                'disembarks' => $disembarks,
            ];
        }

        return $points;
    }
}
