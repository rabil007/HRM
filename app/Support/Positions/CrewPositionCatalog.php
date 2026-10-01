<?php

namespace App\Support\Positions;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Position-only helpers for crew occupational roles after Rank retirement.
 */
final class CrewPositionCatalog
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

    /**
     * @param  Collection<int, CrewAssignment|CrewPlanningAssignment|EmployeeSeaService>  $records
     */
    public static function hydrateCanonicalPositions(Collection $records, int $companyId): void
    {
        if ($companyId < 1 || $records->isEmpty()) {
            return;
        }

        $needsHydration = $records->filter(function (mixed $record): bool {
            if (! $record instanceof CrewAssignment
                && ! $record instanceof CrewPlanningAssignment
                && ! $record instanceof EmployeeSeaService) {
                return false;
            }

            if ($record->relationLoaded('position') && $record->position !== null) {
                return false;
            }

            return (int) ($record->position_id ?? 0) > 0;
        });

        if ($needsHydration->isEmpty()) {
            return;
        }

        $positionIds = $needsHydration
            ->pluck('position_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

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
            $resolvedId = (int) ($record->position_id ?? 0);

            if ($resolvedId > 0 && $positions->has($resolvedId)) {
                $record->setRelation('position', $positions->get($resolvedId));
            }
        }
    }

    public static function resolvedPositionId(int $companyId, ?int $positionId): ?int
    {
        if ($positionId === null || $positionId < 1) {
            return null;
        }

        $usable = Position::query()
            ->where('company_id', $companyId)
            ->whereKey($positionId)
            ->whereNull('deleted_at')
            ->exists();

        return $usable ? (int) $positionId : null;
    }

    public static function resolveCrewAssignmentPositionId(int $companyId, ?int $positionId): ?int
    {
        return self::resolvedPositionId($companyId, $positionId);
    }

    /**
     * @param  list<array{position_id: int, required_count: int}>  $requirements
     * @return list<array{position_id: int, required_count: int}>
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
                'required_count' => (int) ($row['required_count'] ?? 0),
            ];
        }

        return $normalized;
    }
}
