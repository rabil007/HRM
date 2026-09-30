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
     * @return array{id: int, name: string}|null
     */
    public static function option(?CrewAssignment $assignment, int $companyId): ?array
    {
        $name = self::name($assignment, $companyId);

        if ($name === null) {
            return null;
        }

        $id = self::resolvedPositionId($assignment, $companyId);

        return [
            'id' => $id ?? 0,
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

        if ($assignment->position_id !== null && (int) $assignment->position_id > 0) {
            $title = Position::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $assignment->position_id)
                ->value('title');

            if ($title !== null) {
                return (string) $title;
            }
        }

        if ($assignment->rank_id !== null && (int) $assignment->rank_id > 0) {
            $mappedPositionId = RankPositionBridge::positionIdForRank($companyId, (int) $assignment->rank_id);

            if ($mappedPositionId !== null) {
                $title = Position::query()
                    ->where('company_id', $companyId)
                    ->whereKey($mappedPositionId)
                    ->value('title');

                if ($title !== null) {
                    return (string) $title;
                }
            }

            $rank = $assignment->relationLoaded('rank') ? $assignment->rank : null;

            if ($rank !== null) {
                return (string) $rank->name;
            }
        }

        return null;
    }

    private static function resolvedPositionId(?CrewAssignment $assignment, int $companyId): ?int
    {
        if ($assignment === null) {
            return null;
        }

        if ($assignment->position_id !== null && (int) $assignment->position_id > 0) {
            return (int) $assignment->position_id;
        }

        if ($assignment->rank_id !== null && (int) $assignment->rank_id > 0) {
            return RankPositionBridge::positionIdForRank($companyId, (int) $assignment->rank_id);
        }

        return null;
    }
}
