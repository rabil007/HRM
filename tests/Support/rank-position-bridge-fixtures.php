<?php

use App\Enums\RankPositionMatchType;
use App\Models\Company;
use App\Models\Position;
use App\Models\Rank;
use App\Models\RankPositionMapping;

function crewPositionForRank(Company $company, Rank $rank, ?string $title = null): Position
{
    $positionTitle = $title ?? (string) $rank->name;

    $position = Position::query()->firstOrCreate(
        [
            'company_id' => $company->id,
            'title' => $positionTitle,
        ],
        [
            'status' => 'active',
            'is_crew_position' => true,
            'max_tour_of_duty_days' => $rank->max_tour_of_duty_days,
        ],
    );

    if (! $position->is_crew_position || $position->status !== 'active') {
        $position->update([
            'is_crew_position' => true,
            'status' => 'active',
        ]);
    }

    RankPositionMapping::query()->firstOrCreate(
        [
            'company_id' => $company->id,
            'rank_id' => $rank->id,
        ],
        [
            'position_id' => $position->id,
            'match_type' => RankPositionMatchType::Exact,
        ],
    );

    return $position->fresh() ?? $position;
}
