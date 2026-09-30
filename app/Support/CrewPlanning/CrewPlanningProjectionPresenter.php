<?php

namespace App\Support\CrewPlanning;

use App\Models\Position;
use App\Support\Positions\RankPositionBridge;

/**
 * Compact Planning Gantt projection payload from CrewProjectedManningQuery output.
 *
 * Omits per-event employee / assignment identifiers — overlays only need periods
 * and position-level status.
 *
 * CrewProjectedManningQuery may still key internally by legacy Rank until that
 * query is Position-canonical. This presenter batch-maps Rank → Position and
 * never exposes Rank IDs as position_id.
 */
final class CrewPlanningProjectionPresenter
{
    /**
     * @param  array{
     *     from: string,
     *     to: string,
     *     summary: array<string, int>,
     *     items: list<array<string, mixed>>
     * }  $queryResult
     * @return array{
     *     from: string,
     *     to: string,
     *     summary: array{
     *         positions: int,
     *         current_gap_positions: int,
     *         future_gap_positions: int,
     *         covered_positions: int,
     *         overlap_positions: int,
     *         total_projected_shortfall_days: int
     *     },
     *     rows: list<array{
     *         row_key: string,
     *         vessel_id: int,
     *         vessel_name: string,
     *         position_id: int,
     *         position_name: string,
     *         required_count: int,
     *         status: string,
     *         next_gap_date: string|null,
     *         minimum_projected_count: int,
     *         maximum_gap: int,
     *         periods: list<array{
     *             from: string,
     *             to: string,
     *             projected_count: int,
     *             gap: int,
     *             excess: int
     *         }>
     *     }>
     * }
     */
    public static function present(array $queryResult, int $companyId): array
    {
        $rankIds = [];
        $explicitPositionIds = [];

        foreach ($queryResult['items'] as $item) {
            $positionId = (int) ($item['position_id'] ?? 0);
            $rankId = (int) ($item['rank_id'] ?? 0);

            if ($positionId > 0) {
                $explicitPositionIds[] = $positionId;
            } elseif ($rankId > 0) {
                $rankIds[] = $rankId;
            }
        }

        $rankToPosition = RankPositionBridge::positionIdMapForRankIds($companyId, array_values(array_unique($rankIds)));
        $positionIds = array_values(array_unique([
            ...$explicitPositionIds,
            ...array_values($rankToPosition),
        ]));

        $positions = $positionIds === []
            ? collect()
            : Position::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $positionIds)
                ->whereNull('deleted_at')
                ->get(['id', 'title'])
                ->keyBy('id');

        $rows = [];

        foreach ($queryResult['items'] as $item) {
            $vesselId = (int) $item['vessel_id'];
            $resolvedPositionId = (int) ($item['position_id'] ?? 0);

            if ($resolvedPositionId < 1) {
                $rankId = (int) ($item['rank_id'] ?? 0);
                $resolvedPositionId = $rankToPosition[$rankId] ?? 0;
            }

            if ($resolvedPositionId < 1 || ! $positions->has($resolvedPositionId)) {
                continue;
            }

            /** @var Position $position */
            $position = $positions->get($resolvedPositionId);

            $periods = [];

            foreach ($item['periods'] as $period) {
                $periods[] = [
                    'from' => (string) $period['from'],
                    'to' => (string) $period['to'],
                    'projected_count' => (int) $period['projected_count'],
                    'gap' => (int) $period['gap'],
                    'excess' => (int) $period['excess'],
                ];
            }

            $rows[] = [
                'row_key' => self::rowKey($vesselId, $resolvedPositionId),
                'vessel_id' => $vesselId,
                'vessel_name' => (string) $item['vessel_name'],
                'position_id' => $resolvedPositionId,
                'position_name' => (string) $position->title,
                'required_count' => (int) $item['required_count'],
                'status' => (string) $item['status'],
                'next_gap_date' => $item['next_gap_date'] !== null
                    ? (string) $item['next_gap_date']
                    : null,
                'minimum_projected_count' => (int) $item['minimum_projected_count'],
                'maximum_gap' => (int) $item['maximum_gap'],
                'periods' => $periods,
            ];
        }

        return [
            'from' => (string) $queryResult['from'],
            'to' => (string) $queryResult['to'],
            'summary' => [
                'positions' => (int) $queryResult['summary']['positions'],
                'current_gap_positions' => (int) $queryResult['summary']['current_gap_positions'],
                'future_gap_positions' => (int) $queryResult['summary']['future_gap_positions'],
                'covered_positions' => (int) $queryResult['summary']['covered_positions'],
                'overlap_positions' => (int) $queryResult['summary']['overlap_positions'],
                'total_projected_shortfall_days' => (int) $queryResult['summary']['total_projected_shortfall_days'],
            ],
            'rows' => $rows,
        ];
    }

    public static function rowKey(int $vesselId, int $positionId): string
    {
        return CrewPlanningGanttQuery::rowKey($vesselId, $positionId);
    }
}
