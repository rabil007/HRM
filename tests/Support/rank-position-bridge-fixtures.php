<?php

use App\Models\Company;
use App\Models\Position;

/**
 * Phase 3B: Position schema dropped. Returns an active crew Position for the company.
 * Previously mapped a Rank↔Position; now just ensures the Position is a crew position.
 *
 * @deprecated Pass a Position directly. Kept so call sites compile; rename when convenient.
 */
function crewPositionForRank(Company $company, mixed $rankOrPosition, ?string $title = null): Position
{
    // If a Position object is passed, ensure it is a crew position and return it.
    if ($rankOrPosition instanceof Position) {
        if (! $rankOrPosition->is_crew_position || $rankOrPosition->status !== 'active') {
            $rankOrPosition->update([
                'is_crew_position' => true,
                'status' => 'active',
            ]);
        }

        return $rankOrPosition->fresh() ?? $rankOrPosition;
    }

    // Legacy: a non-Position object was passed (old Rank model). Create a new Position.
    $positionTitle = $title ?? 'Position '.uniqid();

    return Position::query()->firstOrCreate(
        [
            'company_id' => $company->id,
            'title' => $positionTitle,
        ],
        [
            'status' => 'active',
            'is_crew_position' => true,
            'max_tour_of_duty_days' => null,
        ],
    );
}
