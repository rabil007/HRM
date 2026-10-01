<?php

namespace App\Support\Positions;

use App\Models\CrewAssignment;
use App\Models\Position;

/**
 * Phase 2: canonical Position labels for crew assignment display/filter output.
 *
 * Phase 3 removal candidate once rank_id is dropped from assignments.
 */
final class CrewAssignmentPositionPresenter
{
    /**
     * @return array{id: int|null, name: string}|null
     */
    public static function option(?CrewAssignment $assignment, int $companyId): ?array
    {
        $name = self::name($assignment, $companyId);

        if ($name === null) {
            return null;
        }

        return [
            'id' => self::resolvedPositionId($assignment, $companyId),
            'name' => $name,
        ];
    }

    public static function name(?CrewAssignment $assignment, int $companyId): ?string
    {
        if ($assignment === null) {
            return null;
        }

        $position = $assignment->relationLoaded('position') ? $assignment->position : null;

        if ($position instanceof Position) {
            return (string) $position->title;
        }

        return null;
    }

    private static function resolvedPositionId(?CrewAssignment $assignment, int $companyId): ?int
    {
        if ($assignment === null) {
            return null;
        }

        return $assignment->position_id !== null ? (int) $assignment->position_id : null;
    }
}
