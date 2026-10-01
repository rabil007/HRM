<?php

namespace App\Support\MasterData;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only Rank-removal readiness checks for Phase 3B.
 *
 * Uses query builder (not Eloquent SoftDeletes scopes) so soft-deleted
 * historical rows are always inspected.
 */
final class RankRemovalReadiness
{
    /**
     * @var list<string>
     */
    private const OPERATIONAL_TABLES = [
        'employees',
        'crew_assignments',
        'crew_planning_assignments',
        'employee_sea_services',
        'vessel_manning',
    ];

    /**
     * Human-readable labels for CLI / troubleshooting output.
     *
     * @var array<string, string>
     */
    public const TOTAL_LABELS = [
        'employees_missing_position' => 'Employees missing Position',
        'employees_position_conflict' => 'Employees Rank/Position conflict',
        'employees_invalid_position' => 'Employees invalid Position',
        'crew_assignments_missing_position' => 'Crew assignments missing Position',
        'crew_assignments_position_conflict' => 'Crew assignments Rank/Position conflict',
        'crew_assignments_invalid_position' => 'Crew assignments invalid Position',
        'crew_planning_assignments_missing_position' => 'Crew planning missing Position',
        'crew_planning_assignments_position_conflict' => 'Crew planning Rank/Position conflict',
        'crew_planning_assignments_invalid_position' => 'Crew planning invalid Position',
        'employee_sea_services_missing_position' => 'Sea services missing Position',
        'employee_sea_services_position_conflict' => 'Sea services Rank/Position conflict',
        'employee_sea_services_invalid_position' => 'Sea services invalid Position',
        'vessel_manning_missing_position' => 'Vessel manning missing Position',
        'vessel_manning_position_conflict' => 'Vessel manning Rank/Position conflict',
        'vessel_manning_invalid_position' => 'Vessel manning invalid Position',
        'vessel_manning_position_collisions' => 'Vessel Manning Position collisions',
        'document_requirements_missing_position' => 'Document requirements missing Position pivot',
        'saved_views_unmapped_rank_filter' => 'Saved views with unmapped rank_id',
        'saved_views_rank_position_conflict' => 'Saved views Rank/Position filter conflict',
        'saved_views_remaining_rank_filter' => 'Saved views still using rank_id',
        'rank_position_tod_conflicts' => 'Rank/Position TOD conflicts',
        'rank_position_status_conflicts' => 'Rank/Position status conflicts',
        'broken_mappings' => 'Broken Rank→Position mappings',
        'missing_required_position_schema' => 'Tables missing required position_id schema',
    ];

    /**
     * @return array{
     *     ready: bool,
     *     already_removed: bool,
     *     companies: list<array<string, mixed>>,
     *     totals: array<string, int>
     * }
     */
    public function report(?int $companyId = null): array
    {
        if (! Schema::hasTable('ranks')) {
            return [
                'ready' => true,
                'already_removed' => true,
                'companies' => [],
                'totals' => $this->emptyTotals(),
            ];
        }

        // Include soft-deleted companies — backfill already processes every tenant row.
        $companies = $companyId !== null
            ? Company::withTrashed()->whereKey($companyId)->get(['id', 'name'])
            : Company::withTrashed()->orderBy('id')->get(['id', 'name']);

        $companyReports = [];
        $totals = $this->emptyTotals();

        foreach ($companies as $company) {
            $report = $this->forCompany((int) $company->id, (string) $company->name);
            $companyReports[] = $report;

            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($report['counts'][$key] ?? 0);
            }
        }

        $schemaMissing = $this->missingRequiredPositionSchema();
        $totals['missing_required_position_schema'] = count($schemaMissing);

        $ready = collect($totals)->every(fn (int $count): bool => $count === 0);

        return [
            'ready' => $ready,
            'already_removed' => false,
            'companies' => $companyReports,
            'totals' => $totals,
            'details' => [
                'missing_required_position_schema' => $schemaMissing,
            ],
        ];
    }

    /**
     * @return array{
     *     company_id: int,
     *     company_name: string,
     *     ready: bool,
     *     counts: array<string, int>,
     *     details: array<string, list<mixed>>
     * }
     */
    public function forCompany(int $companyId, string $companyName = ''): array
    {
        $details = [
            'employees_missing_position' => [],
            'employees_position_conflict' => [],
            'employees_invalid_position' => [],
            'crew_assignments_missing_position' => [],
            'crew_assignments_position_conflict' => [],
            'crew_assignments_invalid_position' => [],
            'crew_planning_assignments_missing_position' => [],
            'crew_planning_assignments_position_conflict' => [],
            'crew_planning_assignments_invalid_position' => [],
            'employee_sea_services_missing_position' => [],
            'employee_sea_services_position_conflict' => [],
            'employee_sea_services_invalid_position' => [],
            'vessel_manning_missing_position' => [],
            'vessel_manning_position_conflict' => [],
            'vessel_manning_invalid_position' => [],
            'vessel_manning_position_collisions' => [],
            'document_requirements_missing_position' => [],
            'saved_views_unmapped_rank_filter' => [],
            'saved_views_rank_position_conflict' => [],
            'saved_views_remaining_rank_filter' => [],
            'rank_position_tod_conflicts' => [],
            'rank_position_status_conflicts' => [],
            'broken_mappings' => [],
        ];

        foreach (self::OPERATIONAL_TABLES as $table) {
            $prefix = $table;
            $inspection = $this->inspectOperationalTable($table, $companyId);
            $details["{$prefix}_missing_position"] = $inspection['missing'];
            $details["{$prefix}_position_conflict"] = $inspection['conflicts'];
            $details["{$prefix}_invalid_position"] = $inspection['invalid'];
        }

        $details['vessel_manning_position_collisions'] = $this->vesselManningPositionCollisions($companyId)->all();
        $details['document_requirements_missing_position'] = $this->documentRequirementsMissingPosition($companyId)->all();
        $details['saved_views_unmapped_rank_filter'] = $this->savedViewsUnmappedRankFilters($companyId)->all();
        $details['saved_views_rank_position_conflict'] = $this->savedViewsRankPositionConflicts($companyId)->all();
        $details['saved_views_remaining_rank_filter'] = $this->savedViewsRemainingRankFilters($companyId)->all();
        $details['rank_position_tod_conflicts'] = $this->rankPositionTodConflicts($companyId)->all();
        $details['rank_position_status_conflicts'] = $this->rankPositionStatusConflicts($companyId)->all();
        $details['broken_mappings'] = $this->brokenMappings($companyId)->pluck('id')->all();

        $counts = [];
        foreach ($details as $key => $items) {
            $counts[$key] = count($items);
        }

        return [
            'company_id' => $companyId,
            'company_name' => $companyName,
            'ready' => collect($counts)->every(fn (int $count): bool => $count === 0),
            'counts' => $counts,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyTotals(): array
    {
        return [
            'employees_missing_position' => 0,
            'employees_position_conflict' => 0,
            'employees_invalid_position' => 0,
            'crew_assignments_missing_position' => 0,
            'crew_assignments_position_conflict' => 0,
            'crew_assignments_invalid_position' => 0,
            'crew_planning_assignments_missing_position' => 0,
            'crew_planning_assignments_position_conflict' => 0,
            'crew_planning_assignments_invalid_position' => 0,
            'employee_sea_services_missing_position' => 0,
            'employee_sea_services_position_conflict' => 0,
            'employee_sea_services_invalid_position' => 0,
            'vessel_manning_missing_position' => 0,
            'vessel_manning_position_conflict' => 0,
            'vessel_manning_invalid_position' => 0,
            'vessel_manning_position_collisions' => 0,
            'document_requirements_missing_position' => 0,
            'saved_views_unmapped_rank_filter' => 0,
            'saved_views_rank_position_conflict' => 0,
            'saved_views_remaining_rank_filter' => 0,
            'rank_position_tod_conflicts' => 0,
            'rank_position_status_conflicts' => 0,
            'broken_mappings' => 0,
            'missing_required_position_schema' => 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function missingRequiredPositionSchema(): array
    {
        $missing = [];

        foreach (self::OPERATIONAL_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'rank_id') && ! Schema::hasColumn($table, 'position_id')) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return array{missing: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
     */
    private function inspectOperationalTable(string $table, int $companyId): array
    {
        $missing = [];
        $conflicts = [];
        $invalid = [];

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rank_id')) {
            return compact('missing', 'conflicts', 'invalid');
        }

        if (! Schema::hasColumn($table, 'position_id')) {
            // Schema gap is reported globally via missing_required_position_schema.
            return compact('missing', 'conflicts', 'invalid');
        }

        $rows = DB::table($table)
            ->where('company_id', $companyId)
            ->where(function ($query): void {
                $query->whereNotNull('rank_id')->orWhereNotNull('position_id');
            })
            ->get(['id', 'company_id', 'rank_id', 'position_id']);

        $mappings = $this->mappingsForCompany($companyId);

        foreach ($rows as $row) {
            $rowId = (int) $row->id;
            $rankId = $row->rank_id !== null ? (int) $row->rank_id : null;
            $positionId = $row->position_id !== null ? (int) $row->position_id : null;

            if ($rankId !== null && $positionId === null) {
                $missing[] = [
                    'id' => $rowId,
                    'rank_id' => $rankId,
                ];

                continue;
            }

            if ($positionId !== null) {
                $position = DB::table('positions')->where('id', $positionId)->first();

                if ($position === null
                    || $position->deleted_at !== null
                    || (int) $position->company_id !== $companyId
                ) {
                    $invalid[] = [
                        'id' => $rowId,
                        'position_id' => $positionId,
                        'position_company_id' => $position !== null ? (int) $position->company_id : null,
                        'position_deleted' => $position !== null && $position->deleted_at !== null,
                        'position_missing' => $position === null,
                    ];
                }
            }

            if ($rankId !== null && $positionId !== null && isset($mappings[$rankId])) {
                $mappedPositionId = $mappings[$rankId];

                if ($mappedPositionId !== $positionId) {
                    $conflicts[] = [
                        'id' => $rowId,
                        'rank_id' => $rankId,
                        'position_id' => $positionId,
                        'mapped_position_id' => $mappedPositionId,
                    ];
                }
            }
        }

        return compact('missing', 'conflicts', 'invalid');
    }

    /**
     * Detect duplicate (company, vessel, position) keys that would break the
     * Position unique index after Rank-keyed uniqueness is dropped.
     *
     * Includes soft-deleted vessel_manning rows. Does not merge counts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function vesselManningPositionCollisions(int $companyId): Collection
    {
        if (! Schema::hasTable('vessel_manning') || ! Schema::hasColumn('vessel_manning', 'position_id')) {
            return collect();
        }

        $columns = ['id', 'company_id', 'vessel_id', 'position_id', 'required_count'];

        if (Schema::hasColumn('vessel_manning', 'rank_id')) {
            $columns[] = 'rank_id';
        }

        $rows = DB::table('vessel_manning')
            ->where('company_id', $companyId)
            ->whereNotNull('position_id')
            ->get($columns);

        return $rows
            ->groupBy(fn (object $row): string => (int) $row->vessel_id.'|'.(int) $row->position_id)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(function (Collection $group) use ($companyId): array {
                /** @var object $first */
                $first = $group->first();

                return [
                    'company_id' => $companyId,
                    'vessel_id' => (int) $first->vessel_id,
                    'position_id' => (int) $first->position_id,
                    'row_ids' => $group->map(fn (object $row): int => (int) $row->id)->values()->all(),
                    'rank_ids' => $group
                        ->map(fn (object $row): ?int => isset($row->rank_id) && $row->rank_id !== null
                            ? (int) $row->rank_id
                            : null)
                        ->filter()
                        ->values()
                        ->all(),
                    'required_counts' => $group
                        ->map(fn (object $row): int => (int) $row->required_count)
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function mappingsForCompany(int $companyId): array
    {
        if (! Schema::hasTable('rank_position_mappings')) {
            return [];
        }

        return DB::table('rank_position_mappings')
            ->where('company_id', $companyId)
            ->pluck('position_id', 'rank_id')
            ->mapWithKeys(fn ($positionId, $rankId): array => [(int) $rankId => (int) $positionId])
            ->all();
    }

    /**
     * @return Collection<int, int>
     */
    private function documentRequirementsMissingPosition(int $companyId): Collection
    {
        if (! Schema::hasTable('document_requirement_rank')) {
            return collect();
        }

        $rows = DB::table('document_requirement_rank as drr')
            ->join('document_requirements as dr', 'dr.id', '=', 'drr.document_requirement_id')
            ->where('dr.company_id', $companyId)
            ->select(['drr.document_requirement_id', 'drr.rank_id'])
            ->get();

        $mappings = $this->mappingsForCompany($companyId);
        $missing = collect();

        foreach ($rows as $row) {
            $mappedPositionId = $mappings[(int) $row->rank_id] ?? null;

            if ($mappedPositionId === null) {
                $missing->push((int) $row->document_requirement_id);

                continue;
            }

            $hasPositionPivot = DB::table('document_requirement_position')
                ->where('document_requirement_id', (int) $row->document_requirement_id)
                ->where('position_id', $mappedPositionId)
                ->exists();

            if (! $hasPositionPivot) {
                $missing->push((int) $row->document_requirement_id);
            }
        }

        return $missing->unique()->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function savedViewsUnmappedRankFilters(int $companyId): Collection
    {
        if (! Schema::hasTable('saved_views')) {
            return collect();
        }

        $mappings = $this->mappingsForCompany($companyId);
        $unmapped = collect();

        $views = DB::table('saved_views')
            ->where('company_id', $companyId)
            ->get(['id', 'filters']);

        foreach ($views as $view) {
            $filters = $this->decodeFilters($view->filters);

            if (! array_key_exists('rank_id', $filters)) {
                continue;
            }

            // Conflicts are reported separately when both keys exist.
            if (array_key_exists('position_id', $filters) && $filters['position_id'] !== null && $filters['position_id'] !== '') {
                continue;
            }

            $rankId = (int) $filters['rank_id'];

            if ($rankId < 1 || ! isset($mappings[$rankId])) {
                $unmapped->push((int) $view->id);
            }
        }

        return $unmapped->values();
    }

    /**
     * Final destructive gate: ANY persisted rank_id filter blocks removal,
     * even when a Rank→Position mapping exists (saved-view conversion must run).
     *
     * @return Collection<int, int>
     */
    private function savedViewsRemainingRankFilters(int $companyId): Collection
    {
        if (! Schema::hasTable('saved_views')) {
            return collect();
        }

        $remaining = collect();

        $views = DB::table('saved_views')
            ->where('company_id', $companyId)
            ->get(['id', 'filters']);

        foreach ($views as $view) {
            $filters = $this->decodeFilters($view->filters);

            if (array_key_exists('rank_id', $filters)) {
                $remaining->push((int) $view->id);
            }
        }

        return $remaining->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function savedViewsRankPositionConflicts(int $companyId): Collection
    {
        if (! Schema::hasTable('saved_views')) {
            return collect();
        }

        $mappings = $this->mappingsForCompany($companyId);
        $conflicts = collect();

        $views = DB::table('saved_views')
            ->where('company_id', $companyId)
            ->get(['id', 'filters']);

        foreach ($views as $view) {
            $filters = $this->decodeFilters($view->filters);

            if (! array_key_exists('rank_id', $filters)) {
                continue;
            }

            if (! array_key_exists('position_id', $filters) || $filters['position_id'] === null || $filters['position_id'] === '') {
                continue;
            }

            $rankId = (int) $filters['rank_id'];
            $positionId = (int) $filters['position_id'];
            $mappedPositionId = $mappings[$rankId] ?? null;

            $position = DB::table('positions')->where('id', $positionId)->first();
            $positionInvalid = $position === null
                || $position->deleted_at !== null
                || (int) $position->company_id !== $companyId;

            $mappingConflict = $mappedPositionId !== null && $mappedPositionId !== $positionId;

            if ($positionInvalid || $mappingConflict || $mappedPositionId === null) {
                $conflicts->push([
                    'id' => (int) $view->id,
                    'rank_id' => $rankId,
                    'position_id' => $positionId,
                    'mapped_position_id' => $mappedPositionId,
                    'position_invalid' => $positionInvalid,
                ]);
            }
        }

        return $conflicts->values();
    }

    /**
     * Flag mapped Rank/Position pairs where both TOD values are set and disagree.
     * Does not pick a winner — operators must resolve before destructive removal.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rankPositionTodConflicts(int $companyId): Collection
    {
        if (! Schema::hasTable('rank_position_mappings') || ! Schema::hasTable('ranks')) {
            return collect();
        }

        $conflicts = collect();

        $rows = DB::table('rank_position_mappings as rpm')
            ->join('ranks as r', 'r.id', '=', 'rpm.rank_id')
            ->join('positions as p', 'p.id', '=', 'rpm.position_id')
            ->where('rpm.company_id', $companyId)
            ->whereNotNull('r.max_tour_of_duty_days')
            ->whereNotNull('p.max_tour_of_duty_days')
            ->select([
                'rpm.company_id',
                'rpm.rank_id',
                'r.name as rank_name',
                'r.max_tour_of_duty_days as rank_tod_days',
                'rpm.position_id',
                'p.title as position_title',
                'p.max_tour_of_duty_days as position_tod_days',
            ])
            ->get();

        foreach ($rows as $row) {
            if ((int) $row->rank_tod_days === (int) $row->position_tod_days) {
                continue;
            }

            $conflicts->push([
                'company_id' => (int) $row->company_id,
                'rank_id' => (int) $row->rank_id,
                'rank_name' => (string) $row->rank_name,
                'rank_tod_days' => (int) $row->rank_tod_days,
                'position_id' => (int) $row->position_id,
                'position_title' => (string) $row->position_title,
                'position_tod_days' => (int) $row->position_tod_days,
            ]);
        }

        return $conflicts->values();
    }

    /**
     * Flag active (non-deleted) Ranks mapped to inactive Positions.
     * Does not auto-activate Positions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rankPositionStatusConflicts(int $companyId): Collection
    {
        if (! Schema::hasTable('rank_position_mappings') || ! Schema::hasTable('ranks')) {
            return collect();
        }

        $query = DB::table('rank_position_mappings as rpm')
            ->join('ranks as r', 'r.id', '=', 'rpm.rank_id')
            ->join('positions as p', 'p.id', '=', 'rpm.position_id')
            ->where('rpm.company_id', $companyId)
            ->where('r.is_active', true)
            ->where('p.status', '!=', 'active')
            ->select([
                'rpm.company_id',
                'rpm.rank_id',
                'r.name as rank_name',
                'r.is_active as rank_is_active',
                'rpm.position_id',
                'p.title as position_title',
                'p.status as position_status',
            ]);

        if (Schema::hasColumn('ranks', 'deleted_at')) {
            $query->whereNull('r.deleted_at');
        }

        return $query
            ->get()
            ->map(fn (object $row): array => [
                'company_id' => (int) $row->company_id,
                'rank_id' => (int) $row->rank_id,
                'rank_name' => (string) $row->rank_name,
                'rank_is_active' => (bool) $row->rank_is_active,
                'position_id' => (int) $row->position_id,
                'position_title' => (string) $row->position_title,
                'position_status' => (string) $row->position_status,
            ])
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function brokenMappings(int $companyId): Collection
    {
        if (! Schema::hasTable('rank_position_mappings')) {
            return collect();
        }

        return DB::table('rank_position_mappings as rpm')
            ->leftJoin('positions as p', 'p.id', '=', 'rpm.position_id')
            ->leftJoin('ranks as r', 'r.id', '=', 'rpm.rank_id')
            ->where('rpm.company_id', $companyId)
            ->select([
                'rpm.id',
                'rpm.company_id',
                'rpm.rank_id',
                'rpm.position_id',
                'p.company_id as position_company_id',
                'p.deleted_at as position_deleted_at',
                'r.id as rank_exists_id',
            ])
            ->get()
            ->filter(function (object $mapping): bool {
                if ($mapping->position_id === null || $mapping->position_company_id === null) {
                    return true;
                }

                if ($mapping->position_deleted_at !== null) {
                    return true;
                }

                if ((int) $mapping->position_company_id !== (int) $mapping->company_id) {
                    return true;
                }

                return $mapping->rank_exists_id === null;
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFilters(mixed $filters): array
    {
        if (is_array($filters)) {
            return $filters;
        }

        if (is_string($filters) && $filters !== '') {
            $decoded = json_decode($filters, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
