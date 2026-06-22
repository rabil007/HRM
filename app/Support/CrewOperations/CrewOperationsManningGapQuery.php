<?php

namespace App\Support\CrewOperations;

use App\Models\EmployeeDeployment;
use App\Models\VesselManning;
use App\Support\CrewDeployments\DeploymentStatus;
use Carbon\CarbonImmutable;

final class CrewOperationsManningGapQuery
{
    private const ITEM_LIMIT = 20;

    /**
     * Compare configured vessel manning requirements against on-vessel deployments by rank.
     *
     * On-vessel headcount uses {@see DeploymentStatus::resolve()} with status `on_vessel`.
     *
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
     *     }>
     * }
     */
    public static function forCompany(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $actualCounts = self::onVesselCountsByVesselRank($companyId, $today);

        $items = VesselManning::query()
            ->where('company_id', $companyId)
            ->with(['vessel:id,name', 'rank:id,name'])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->get()
            ->map(function (VesselManning $row) use ($actualCounts): array {
                $key = self::vesselRankKey((int) $row->vessel_id, (int) $row->rank_id);
                $actual = $actualCounts[$key] ?? 0;
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
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function onVesselCountsByVesselRank(int $companyId, CarbonImmutable $today): array
    {
        $counts = [];

        EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->get(['id', 'vessel_id', 'rank_id', 'joined_date', 'disembarked_date', 'arrived_date', 'join_standby_from', 'join_standby_to', 'leave_standby_from', 'leave_standby_to', 'travelled_date'])
            ->each(function (EmployeeDeployment $deployment) use (&$counts, $today): void {
                if (DeploymentStatus::resolve($deployment, $today)['status'] !== DeploymentStatus::ON_VESSEL) {
                    return;
                }

                $key = self::vesselRankKey((int) $deployment->vessel_id, (int) $deployment->rank_id);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    private static function vesselRankKey(int $vesselId, int $rankId): string
    {
        return $vesselId.'|'.$rankId;
    }
}
