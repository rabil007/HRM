<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignmentPhase;

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

            $joins = (int) CrewAssignmentPhase::query()
                ->where('company_id', $companyId)
                ->where('phase_code', CrewPhaseCode::OnVessel)
                ->whereNotNull('actual_start_at')
                ->whereBetween('actual_start_at', [$start, $end])
                ->count();

            $disembarks = (int) CrewAssignmentPhase::query()
                ->where('company_id', $companyId)
                ->where('phase_code', CrewPhaseCode::OnVessel)
                ->whereNotNull('actual_end_at')
                ->whereBetween('actual_end_at', [$start, $end])
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
