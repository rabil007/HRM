<?php

namespace App\Support\CrewPlanning;

/**
 * Compact Planning Gantt projection payload from CrewProjectedManningQuery output.
 *
 * Omits per-event employee / assignment identifiers — overlays only need periods
 * and position-level status.
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
     *         rank_id: int,
     *         rank_name: string,
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
    public static function present(array $queryResult): array
    {
        $rows = [];

        foreach ($queryResult['items'] as $item) {
            $vesselId = (int) $item['vessel_id'];
            $rankId = (int) $item['rank_id'];

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
                'row_key' => self::rowKey($vesselId, $rankId),
                'vessel_id' => $vesselId,
                'vessel_name' => (string) $item['vessel_name'],
                'rank_id' => $rankId,
                'rank_name' => (string) $item['rank_name'],
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

    public static function rowKey(int $vesselId, int $rankId): string
    {
        return "vessel:{$vesselId}|rank:{$rankId}";
    }
}
