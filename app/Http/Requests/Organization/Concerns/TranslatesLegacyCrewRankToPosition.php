<?php

namespace App\Http\Requests\Organization\Concerns;

use App\Support\Positions\RankPositionBridge;

/**
 * Temporary Phase 2: accept legacy rank_id on crew assignment payloads and merge position_id.
 */
trait TranslatesLegacyCrewRankToPosition
{
    protected function resolveCurrentCompanyIdForLegacyRank(): int
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($companyId > 0) {
            return $companyId;
        }

        return (int) ($this->user()?->current_company_id ?? 0);
    }

    protected function mergeLegacyCrewPositionFromRank(
        ?int $companyId = null,
        string $positionKey = 'position_id',
        string $rankKey = 'rank_id',
    ): void {
        $companyId ??= $this->resolveCurrentCompanyIdForLegacyRank();

        if ($companyId < 1) {
            return;
        }

        $position = $this->input($positionKey);

        if ($position !== null && $position !== '') {
            return;
        }

        $rankId = $this->input($rankKey);

        if ($rankId === null || $rankId === '') {
            return;
        }

        $positionId = RankPositionBridge::positionIdForRank($companyId, (int) $rankId);

        if ($positionId === null) {
            return;
        }

        $this->merge([$positionKey => $positionId]);
    }

    /**
     * @param  list<array<string, mixed>>  $crew
     * @return list<array<string, mixed>>
     */
    protected function mergeLegacyCrewPositionFromRankInCrewRows(
        int $companyId,
        array $crew,
        string $positionKey = 'position_id',
        string $rankKey = 'rank_id',
    ): array {
        foreach ($crew as $index => $row) {
            $position = $row[$positionKey] ?? null;

            if ($position !== null && $position !== '') {
                continue;
            }

            $rankId = $row[$rankKey] ?? null;

            if ($rankId === null || $rankId === '') {
                continue;
            }

            $positionId = RankPositionBridge::positionIdForRank($companyId, (int) $rankId);

            if ($positionId === null) {
                continue;
            }

            $crew[$index][$positionKey] = $positionId;
        }

        return $crew;
    }
}
