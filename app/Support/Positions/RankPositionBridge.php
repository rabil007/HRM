<?php

namespace App\Support\Positions;

use App\Models\Position;
use App\Models\RankPositionMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Temporary Rank↔Position bridge for Phase 2 cutover.
 *
 * Position is canonical for active application behavior. Rank IDs are only
 * resolved through tenant-aware mappings for legacy dual-write / URL / import
 * compatibility until Phase 3 removes Rank schema.
 */
final class RankPositionBridge
{
    /**
     * @return Builder<Position>
     */
    public static function crewPositionsQuery(int $companyId): Builder
    {
        return Position::query()
            ->where('company_id', $companyId)
            ->where('is_crew_position', true)
            ->where('status', 'active')
            ->whereNull('deleted_at');
    }

    /**
     * @return Builder<Position>
     */
    public static function companyPositionsQuery(int $companyId): Builder
    {
        return Position::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');
    }

    public static function existsCrewPositionRule(int $companyId): Exists
    {
        return Rule::exists('positions', 'id')
            ->where('company_id', $companyId)
            ->where('is_crew_position', true)
            ->where('status', 'active')
            ->whereNull('deleted_at');
    }

    public static function existsCompanyPositionRule(int $companyId): Exists
    {
        return Rule::exists('positions', 'id')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');
    }

    /**
     * @return list<array{id: int, name: string, max_tour_of_duty_days: int|null}>
     */
    public static function crewPositionOptions(int $companyId): array
    {
        return self::crewPositionsQuery($companyId)
            ->orderBy('title')
            ->get(['id', 'title', 'max_tour_of_duty_days'])
            ->map(fn (Position $position): array => [
                'id' => (int) $position->id,
                'name' => (string) $position->title,
                'max_tour_of_duty_days' => $position->max_tour_of_duty_days !== null
                    ? (int) $position->max_tour_of_duty_days
                    : null,
            ])
            ->values()
            ->all();
    }

    public static function positionIdForRank(int $companyId, ?int $rankId): ?int
    {
        if ($rankId === null || $rankId < 1 || $companyId < 1) {
            return null;
        }

        $positionId = RankPositionMapping::query()
            ->where('company_id', $companyId)
            ->where('rank_id', $rankId)
            ->value('position_id');

        if ($positionId === null) {
            return null;
        }

        $usable = Position::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $positionId)
            ->whereNull('deleted_at')
            ->exists();

        return $usable ? (int) $positionId : null;
    }

    /**
     * Legacy dual-write helper: resolve Rank for a Position when schema still
     * requires rank_id. Prefer mapping; never invent Rank IDs.
     */
    public static function rankIdForPosition(int $companyId, ?int $positionId): ?int
    {
        if ($positionId === null || $positionId < 1 || $companyId < 1) {
            return null;
        }

        $rankId = RankPositionMapping::query()
            ->where('company_id', $companyId)
            ->where('position_id', $positionId)
            ->orderBy('id')
            ->value('rank_id');

        return $rankId !== null ? (int) $rankId : null;
    }

    /**
     * Resolve assignment Position from stored position_id or legacy rank_id mapping.
     */
    public static function resolveCrewAssignmentPositionId(int $companyId, ?int $positionId, ?int $rankId): ?int
    {
        if ($positionId !== null && $positionId > 0) {
            return (int) $positionId;
        }

        return self::positionIdForRank($companyId, $rankId);
    }

    /**
     * Normalize employee HR assignment fields for Phase 2 cutover.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function syncEmployeePositionAndRank(array $data, int $companyId): array
    {
        if ($companyId < 1) {
            return $data;
        }

        $positionId = isset($data['position_id']) && $data['position_id'] !== ''
            ? (int) $data['position_id']
            : null;
        if ($positionId !== null && $positionId < 1) {
            $positionId = null;
        }

        $rankId = isset($data['rank_id']) && $data['rank_id'] !== ''
            ? (int) $data['rank_id']
            : null;
        if ($rankId !== null && $rankId < 1) {
            $rankId = null;
        }

        if ($positionId === null && $rankId !== null) {
            $mappedPositionId = self::positionIdForRank($companyId, $rankId);

            if ($mappedPositionId !== null) {
                $data['position_id'] = $mappedPositionId;
                $positionId = $mappedPositionId;
            }
        }

        if ($positionId !== null) {
            $mappedRankId = self::rankIdForPosition($companyId, $positionId);

            if ($mappedRankId !== null) {
                $data['rank_id'] = $mappedRankId;
            }
        }

        return $data;
    }

    public static function resolvedPositionId(int $companyId, ?int $positionId, ?int $rankId): ?int
    {
        if ($positionId !== null && $positionId > 0) {
            $usable = Position::query()
                ->where('company_id', $companyId)
                ->whereKey($positionId)
                ->whereNull('deleted_at')
                ->exists();

            return $usable ? (int) $positionId : null;
        }

        return self::positionIdForRank($companyId, $rankId);
    }

    /**
     * @param  list<array{position_id: int, required_count: int}>  $requirements
     * @return list<array{position_id: int, rank_id: int|null, required_count: int}>
     */
    public static function normalizeManningRequirements(int $companyId, array $requirements): array
    {
        $normalized = [];

        foreach ($requirements as $row) {
            $positionId = (int) ($row['position_id'] ?? 0);

            if ($positionId < 1) {
                continue;
            }

            $normalized[] = [
                'position_id' => $positionId,
                'rank_id' => self::rankIdForPosition($companyId, $positionId),
                'required_count' => (int) ($row['required_count'] ?? 0),
            ];
        }

        return $normalized;
    }
}
