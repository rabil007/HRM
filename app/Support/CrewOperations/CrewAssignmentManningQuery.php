<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\VesselManning;
use App\Support\CrewMovements\CurrentOnboardCrewQuery;
use App\Support\Employees\ActiveEmployeeConstraint;
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
     *         position_id: int,
     *         position_name: string,
     *         required_count: int,
     *         actual_count: int,
     *         gap: int
     *     }>,
     *     onboard_by_vessel_position: array<string, int>,
     *     planned_joins_by_vessel_position: array<string, int>,
     *     planned_signoffs_by_vessel_position: array<string, int>
     * }
     */
    public static function forCompany(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $onboard = self::onboardCountsByVesselPosition($companyId);
        $plannedJoins = self::plannedJoinCountsByVesselPosition($companyId, $today);
        $plannedSignoffs = self::plannedSignoffCountsByVesselPosition($companyId, $today);

        $items = VesselManning::query()
            ->where('company_id', $companyId)
            ->whereNotNull('position_id')
            ->with(['vessel:id,name', 'position:id,title'])
            ->orderBy('vessel_id')
            ->orderBy('position_id')
            ->get()
            ->map(function (VesselManning $row) use ($onboard): array {
                $key = self::vesselPositionKey((int) $row->vessel_id, (int) $row->position_id);
                $actual = $onboard[$key] ?? 0;
                $required = (int) $row->required_count;

                return [
                    'vessel_id' => (int) $row->vessel_id,
                    'vessel_name' => $row->vessel?->name ?? '',
                    'position_id' => (int) $row->position_id,
                    'position_name' => $row->position?->title ?? '',
                    'required_count' => $required,
                    'actual_count' => $actual,
                    'gap' => $required - $actual,
                ];
            })
            ->filter(fn (array $item): bool => $item['gap'] > 0)
            ->sortBy([
                ['gap', 'desc'],
                ['vessel_name', 'asc'],
                ['position_name', 'asc'],
            ])
            ->values()
            ->take(self::ITEM_LIMIT)
            ->all();

        return [
            'understaffed_positions' => count($items),
            'total_shortfall' => array_sum(array_column($items, 'gap')),
            'items' => $items,
            'onboard_by_vessel_position' => $onboard,
            'planned_joins_by_vessel_position' => $plannedJoins,
            'planned_signoffs_by_vessel_position' => $plannedSignoffs,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function onboardCountsByVesselPosition(int $companyId): array
    {
        $counts = [];

        $query = CurrentOnboardCrewQuery::applyConstraint(CrewAssignment::query(), $companyId)
            ->whereNotNull('position_id');

        $query
            ->get(['id', 'vessel_id', 'position_id'])
            ->each(function (CrewAssignment $assignment) use (&$counts): void {
                $key = self::vesselPositionKey((int) $assignment->vessel_id, (int) $assignment->position_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public static function plannedJoinCountsByVesselPosition(int $companyId, CarbonImmutable $today): array
    {
        $counts = [];

        $query = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNotNull('vessel_id')
            ->whereNotNull('position_id')
            ->whereNotNull('planned_join_at')
            ->whereDate('planned_join_at', '>', $today->toDateString())
            ->whereHas('currentPhase', function ($query): void {
                $query->whereIn(
                    'phase_code',
                    array_map(
                        fn (CrewPhaseCode $code): string => $code->value,
                        CrewPhaseCode::plannedJoinForecastPhases(),
                    ),
                )->where('status', CrewPhaseStatus::Active);
            });

        ActiveEmployeeConstraint::whereHas($query, $companyId);

        $query
            ->get(['id', 'vessel_id', 'position_id'])
            ->each(function (CrewAssignment $assignment) use (&$counts): void {
                $key = self::vesselPositionKey((int) $assignment->vessel_id, (int) $assignment->position_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public static function plannedSignoffCountsByVesselPosition(int $companyId, CarbonImmutable $today): array
    {
        $counts = [];

        $query = CurrentOnboardCrewQuery::applyConstraint(CrewAssignment::query(), $companyId)
            ->whereNotNull('position_id')
            ->whereNotNull('planned_signoff_at')
            ->whereDate('planned_signoff_at', '>=', $today->toDateString());

        $query
            ->get(['id', 'vessel_id', 'position_id'])
            ->each(function (CrewAssignment $assignment) use (&$counts): void {
                $key = self::vesselPositionKey((int) $assignment->vessel_id, (int) $assignment->position_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    private static function vesselPositionKey(int $vesselId, int $positionId): string
    {
        return $vesselId.'|'.$positionId;
    }
}
