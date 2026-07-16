<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\VesselManning;
use Carbon\CarbonImmutable;

final class CrewAssignmentManningQuery
{
    private const ITEM_LIMIT = 20;

    /**
     * @return array{
     *     understaffed_positions: int,
     *     total_shortfall: int,
     *     items: list<array{
     *         vessel_id: int,
     *         vessel_name: string,
     *         rank_id: int,
     *         rank_name: string,
     *         required_count: int,
     *         actual_count: int,
     *         gap: int
     *     }>,
     *     onboard_by_vessel_rank: array<string, int>,
     *     planned_joins_by_vessel_rank: array<string, int>,
     *     planned_signoffs_by_vessel_rank: array<string, int>
     * }
     */
    public static function forCompany(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $onboard = self::onboardCountsByVesselRank($companyId);
        $plannedJoins = self::plannedJoinCountsByVesselRank($companyId, $today);
        $plannedSignoffs = self::plannedSignoffCountsByVesselRank($companyId, $today);

        $items = VesselManning::query()
            ->where('company_id', $companyId)
            ->with(['vessel:id,name', 'rank:id,name'])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->get()
            ->map(function (VesselManning $row) use ($onboard): array {
                $key = self::vesselRankKey((int) $row->vessel_id, (int) $row->rank_id);
                $actual = $onboard[$key] ?? 0;
                $required = (int) $row->required_count;

                return [
                    'vessel_id' => (int) $row->vessel_id,
                    'vessel_name' => $row->vessel?->name ?? '',
                    'rank_id' => (int) $row->rank_id,
                    'rank_name' => $row->rank?->name ?? '',
                    'required_count' => $required,
                    'actual_count' => $actual,
                    'gap' => $required - $actual,
                ];
            })
            ->filter(fn (array $item): bool => $item['gap'] > 0)
            ->sortBy([
                ['gap', 'desc'],
                ['vessel_name', 'asc'],
                ['rank_name', 'asc'],
            ])
            ->values()
            ->take(self::ITEM_LIMIT)
            ->all();

        return [
            'understaffed_positions' => count($items),
            'total_shortfall' => array_sum(array_column($items, 'gap')),
            'items' => $items,
            'onboard_by_vessel_rank' => $onboard,
            'planned_joins_by_vessel_rank' => $plannedJoins,
            'planned_signoffs_by_vessel_rank' => $plannedSignoffs,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function onboardCountsByVesselRank(int $companyId): array
    {
        $counts = [];

        CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->whereHas('currentPhase', function ($query): void {
                $query->where('phase_code', CrewPhaseCode::OnVessel)
                    ->where('status', CrewPhaseStatus::Active);
            })
            ->get(['id', 'vessel_id', 'rank_id'])
            ->each(function (CrewAssignment $assignment) use (&$counts): void {
                $key = self::vesselRankKey((int) $assignment->vessel_id, (int) $assignment->rank_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public static function plannedJoinCountsByVesselRank(int $companyId, CarbonImmutable $today): array
    {
        $counts = [];

        CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->whereNotNull('planned_join_at')
            ->whereDate('planned_join_at', '>', $today->toDateString())
            ->whereHas('currentPhase', function ($query): void {
                $query->where('phase_code', CrewPhaseCode::ReadyToJoin)
                    ->where('status', CrewPhaseStatus::Active);
            })
            ->get(['id', 'vessel_id', 'rank_id'])
            ->each(function (CrewAssignment $assignment) use (&$counts): void {
                $key = self::vesselRankKey((int) $assignment->vessel_id, (int) $assignment->rank_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public static function plannedSignoffCountsByVesselRank(int $companyId, CarbonImmutable $today): array
    {
        $counts = [];

        CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->whereNotNull('planned_signoff_at')
            ->whereDate('planned_signoff_at', '>=', $today->toDateString())
            ->whereHas('currentPhase', function ($query): void {
                $query->where('phase_code', CrewPhaseCode::OnVessel)
                    ->where('status', CrewPhaseStatus::Active);
            })
            ->get(['id', 'vessel_id', 'rank_id'])
            ->each(function (CrewAssignment $assignment) use (&$counts): void {
                $key = self::vesselRankKey((int) $assignment->vessel_id, (int) $assignment->rank_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    private static function vesselRankKey(int $vesselId, int $rankId): string
    {
        return $vesselId.'|'.$rankId;
    }
}
