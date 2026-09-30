<?php

namespace App\Support\Positions;

use App\Models\CrewAssignment;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\RankPositionMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
    /** @var array<string, int|null> */
    private static array $positionIdForRankCache = [];

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

        $cacheKey = $companyId.':'.$rankId;

        if (array_key_exists($cacheKey, self::$positionIdForRankCache)) {
            return self::$positionIdForRankCache[$cacheKey];
        }

        self::positionIdMapForRankIds($companyId, [$rankId]);

        return self::$positionIdForRankCache[$cacheKey] ?? null;
    }

    /**
     * @param  list<int>  $rankIds
     * @return array<int, int> rank_id => position_id
     */
    public static function positionIdMapForRankIds(int $companyId, array $rankIds): array
    {
        if ($companyId < 1 || $rankIds === []) {
            return [];
        }

        $normalizedRankIds = collect($rankIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedRankIds === []) {
            return [];
        }

        $uncachedRankIds = [];

        foreach ($normalizedRankIds as $rankId) {
            $cacheKey = $companyId.':'.$rankId;

            if (! array_key_exists($cacheKey, self::$positionIdForRankCache)) {
                $uncachedRankIds[] = $rankId;
            }
        }

        if ($uncachedRankIds !== []) {
            $mappings = RankPositionMapping::query()
                ->where('company_id', $companyId)
                ->whereIn('rank_id', $uncachedRankIds)
                ->get(['rank_id', 'position_id']);

            $positionIds = $mappings
                ->pluck('position_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            $usablePositionIds = $positionIds === []
                ? []
                : Position::query()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $positionIds)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

            $usableLookup = array_fill_keys($usablePositionIds, true);

            foreach ($uncachedRankIds as $rankId) {
                self::$positionIdForRankCache[$companyId.':'.$rankId] = null;
            }

            foreach ($mappings as $mapping) {
                $rankId = (int) $mapping->rank_id;
                $positionId = (int) $mapping->position_id;

                if (isset($usableLookup[$positionId])) {
                    self::$positionIdForRankCache[$companyId.':'.$rankId] = $positionId;
                }
            }
        }

        $map = [];

        foreach ($normalizedRankIds as $rankId) {
            $cached = self::$positionIdForRankCache[$companyId.':'.$rankId] ?? null;

            if ($cached !== null) {
                $map[$rankId] = $cached;
            }
        }

        return $map;
    }

    /**
     * Eager-load Position models for legacy rank-only rows without per-row mapping queries.
     *
     * @param  Collection<int, CrewAssignment>|Collection<int, EmployeeSeaService>  $records
     */
    public static function hydrateCanonicalPositions(Collection $records, int $companyId): void
    {
        if ($companyId < 1 || $records->isEmpty()) {
            return;
        }

        $needsHydration = $records->filter(function (CrewAssignment|EmployeeSeaService $record): bool {
            if ($record->relationLoaded('position') && $record->position !== null) {
                return false;
            }

            return (int) ($record->position_id ?? 0) > 0
                || (int) ($record->rank_id ?? 0) > 0;
        });

        if ($needsHydration->isEmpty()) {
            return;
        }

        $rankIds = $needsHydration
            ->filter(fn (CrewAssignment|EmployeeSeaService $record): bool => (int) ($record->position_id ?? 0) < 1)
            ->pluck('rank_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $rankToPosition = self::positionIdMapForRankIds($companyId, $rankIds);

        $explicitPositionIds = $needsHydration
            ->pluck('position_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $positionIds = array_values(array_unique([
            ...$explicitPositionIds,
            ...array_values($rankToPosition),
        ]));

        if ($positionIds === []) {
            return;
        }

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $positionIds)
            ->whereNull('deleted_at')
            ->get(['id', 'title', 'max_tour_of_duty_days'])
            ->keyBy('id');

        foreach ($needsHydration as $record) {
            $resolvedId = (int) ($record->position_id ?? 0) > 0
                ? (int) $record->position_id
                : ($rankToPosition[(int) ($record->rank_id ?? 0)] ?? null);

            if ($resolvedId !== null && $positions->has($resolvedId)) {
                $record->setRelation('position', $positions->get($resolvedId));
            }
        }
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
