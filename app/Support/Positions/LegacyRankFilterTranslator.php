<?php

namespace App\Support\Positions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Temporary Phase 2 compatibility: translate legacy rank_id filters/URLs/saved views
 * into position_id via tenant-aware mappings.
 *
 * Phase 3 removal candidate — delete once bookmarks and saved views no longer use rank_id.
 */
final class LegacyRankFilterTranslator
{
    public static function positionIdFromRequest(Request $request, int $companyId): string
    {
        return self::resolvePositionIdString(
            $companyId,
            $request->query('position_id'),
            $request->query('rank_id'),
        );
    }

    public static function resolvePositionIdString(int $companyId, mixed $positionId, mixed $legacyRankId): string
    {
        $resolved = self::resolvePositionId($companyId, $positionId, $legacyRankId);

        return $resolved !== null ? (string) $resolved : '';
    }

    /**
     * @param  Builder<Model>  $query
     */
    public static function whereAssignmentMatchesPosition(
        Builder $query,
        int $companyId,
        int $positionId,
        string $table = 'crew_assignments',
    ): void {
        $mappedRankId = RankPositionBridge::rankIdForPosition($companyId, $positionId);

        $query->where(function (Builder $inner) use ($table, $positionId, $mappedRankId): void {
            $inner->where("{$table}.position_id", $positionId);

            if ($mappedRankId !== null) {
                $inner->orWhere(function (Builder $legacy) use ($table, $mappedRankId): void {
                    $legacy
                        ->whereNull("{$table}.position_id")
                        ->where("{$table}.rank_id", $mappedRankId);
                });
            }
        });
    }

    /**
     * Prefer position_id when present. Otherwise translate legacy rank_id through
     * the company mapping. Never treats a Rank ID as a Position ID.
     */
    public static function resolvePositionId(
        int $companyId,
        mixed $positionId,
        mixed $legacyRankId,
    ): ?int {
        if ($positionId !== null && $positionId !== '') {
            $resolved = (int) $positionId;

            return $resolved > 0 ? $resolved : null;
        }

        if ($legacyRankId === null || $legacyRankId === '') {
            return null;
        }

        return RankPositionBridge::positionIdForRank($companyId, (int) $legacyRankId);
    }

    /**
     * Normalize a filter/saved-view payload: replace rank_id with position_id when needed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function normalizeFilters(int $companyId, array $filters): array
    {
        $positionId = self::resolvePositionId(
            $companyId,
            $filters['position_id'] ?? null,
            $filters['rank_id'] ?? null,
        );

        unset($filters['rank_id']);

        if ($positionId !== null) {
            $filters['position_id'] = $positionId;
        } else {
            unset($filters['position_id']);
        }

        return $filters;
    }
}
