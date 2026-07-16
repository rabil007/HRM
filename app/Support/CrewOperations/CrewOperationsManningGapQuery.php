<?php

namespace App\Support\CrewOperations;

use Carbon\CarbonImmutable;

final class CrewOperationsManningGapQuery
{
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
     *     }>
     * }
     */
    public static function forCompany(int $companyId, ?CarbonImmutable $today = null): array
    {
        $result = CrewAssignmentManningQuery::forCompany($companyId, $today);

        return [
            'understaffed_positions' => $result['understaffed_positions'],
            'total_shortfall' => $result['total_shortfall'],
            'items' => $result['items'],
        ];
    }
}
