<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\Vessel;
use App\Models\VesselManning;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CurrentCrewVesselQuery
{
    /**
     * Paginate vessels that currently have matching onboard (active P4) crew.
     *
     * Parent rows are vessels. Each page loads the complete filtered roster
     * for those vessels — assignments are never paginated first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     name: string,
     *     client_name: string|null,
     *     onboard_count: int,
     *     required_count: int,
     *     gap: int,
     *     coverage_label: string,
     *     crew: list<array<string, mixed>>
     * }>
     */
    public static function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $perPage = CurrentCrewQuery::resolvePerPage($filters['per_page'] ?? null);
        $assignmentsQuery = CurrentOnboardCrewQuery::assignments($companyId, $filters);

        $vessels = Vessel::query()
            ->where('company_id', $companyId)
            ->whereIn('id', (clone $assignmentsQuery)->select('vessel_id'))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $pageVesselIds = $vessels->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $crewByVessel = self::matchingCrewByVessel($companyId, $filters, $pageVesselIds);
        $requiredByVessel = self::requiredCountsByVessel($companyId, $pageVesselIds, $filters);

        $vessels->setCollection(
            $vessels->getCollection()->map(function (Vessel $vessel) use ($crewByVessel, $requiredByVessel): array {
                $crew = $crewByVessel->get((int) $vessel->id, collect());
                $onboard = $crew->count();
                $required = $requiredByVessel[(int) $vessel->id] ?? 0;
                $gap = $required - $onboard;

                return [
                    'id' => (int) $vessel->id,
                    'name' => $vessel->name,
                    'client_name' => self::sharedClientName($crew),
                    'onboard_count' => $onboard,
                    'required_count' => $required,
                    'gap' => $gap,
                    'coverage_label' => self::coverageLabel($gap),
                    'crew' => $crew
                        ->map(fn (CrewAssignment $assignment): array => CrewAssignmentPresenter::listItem($assignment))
                        ->values()
                        ->all(),
                ];
            }),
        );

        return $vessels;
    }

    /**
     * All matching onboard assignments for export (not limited to the current page).
     *
     * Optional assignment IDs are intersected with the authoritative filtered
     * onboard query so client-supplied IDs cannot expand the result set.
     *
     * When $selectedOnly is true, the query is always restricted to the supplied
     * IDs (an empty list matches nothing). Callers must not treat an empty
     * selected result as "export all".
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CrewAssignment>
     */
    public static function exportAssignments(int $companyId, array $filters = [], mixed $assignmentIds = [], bool $selectedOnly = false): Collection
    {
        $query = CurrentOnboardCrewQuery::assignments($companyId, $filters);

        $selectedIds = self::sanitizeAssignmentIds($assignmentIds);

        if ($selectedOnly || $selectedIds !== []) {
            $query->whereIn('id', $selectedIds === [] ? [0] : $selectedIds);
        }

        CurrentCrewQuery::eagerLoadForList($query);

        $assignments = $query
            ->with(['vessel', 'rank', 'employee'])
            ->get()
            ->sortBy([
                fn (CrewAssignment $assignment): string => (string) ($assignment->vessel?->name ?? ''),
                fn (CrewAssignment $assignment): string => (string) ($assignment->rank?->name ?? ''),
                fn (CrewAssignment $assignment): string => (string) ($assignment->employee?->name ?? ''),
            ])
            ->values();

        CurrentCrewQuery::attachReliefReadiness($assignments, $companyId);

        return $assignments;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $vesselIds
     * @return Collection<int, Collection<int, CrewAssignment>>
     */
    private static function matchingCrewByVessel(int $companyId, array $filters, array $vesselIds): Collection
    {
        if ($vesselIds === []) {
            return collect();
        }

        $query = CurrentOnboardCrewQuery::assignments($companyId, $filters)
            ->whereIn('vessel_id', $vesselIds);

        CurrentCrewQuery::eagerLoadForList($query);

        $assignments = $query->get();
        CurrentCrewQuery::attachReliefReadiness($assignments, $companyId);

        return $assignments
            ->sortBy([
                fn (CrewAssignment $assignment): string => (string) ($assignment->rank?->name ?? ''),
                fn (CrewAssignment $assignment): string => (string) ($assignment->employee?->name ?? ''),
            ])
            ->groupBy(fn (CrewAssignment $assignment): int => (int) $assignment->vessel_id);
    }

    /**
     * @param  list<int>  $vesselIds
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private static function requiredCountsByVessel(int $companyId, array $vesselIds, array $filters): array
    {
        if ($vesselIds === []) {
            return [];
        }

        $query = VesselManning::query()
            ->where('company_id', $companyId)
            ->whereIn('vessel_id', $vesselIds);

        if (! empty($filters['rank_id'])) {
            $query->where('rank_id', (int) $filters['rank_id']);
        }

        return $query
            ->selectRaw('vessel_id, SUM(required_count) as required_total')
            ->groupBy('vessel_id')
            ->pluck('required_total', 'vessel_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @param  Collection<int, CrewAssignment>  $crew
     */
    private static function sharedClientName(Collection $crew): ?string
    {
        $names = $crew
            ->map(fn (CrewAssignment $assignment): ?string => $assignment->client?->name)
            ->filter()
            ->unique()
            ->values();

        return $names->count() === 1 ? (string) $names->first() : null;
    }

    private static function coverageLabel(int $gap): string
    {
        if ($gap > 0) {
            return 'Short '.$gap;
        }

        if ($gap < 0) {
            return '+'.abs($gap).' above requirement';
        }

        return 'Fully manned';
    }

    /**
     * @return list<int>
     */
    public static function sanitizeAssignmentIds(mixed $raw): array
    {
        $ids = is_array($raw) ? $raw : (($raw === null || $raw === '') ? [] : [$raw]);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
