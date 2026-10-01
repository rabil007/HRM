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

        $companies = $companyId !== null
            ? Company::query()->whereKey($companyId)->get(['id', 'name'])
            : Company::query()->orderBy('id')->get(['id', 'name']);

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
            'document_requirements_missing_position' => [],
            'saved_views_unmapped_rank_filter' => [],
            'saved_views_rank_position_conflict' => [],
            'broken_mappings' => [],
        ];

        foreach (self::OPERATIONAL_TABLES as $table) {
            $prefix = $table;
            $inspection = $this->inspectOperationalTable($table, $companyId);
            $details["{$prefix}_missing_position"] = $inspection['missing'];
            $details["{$prefix}_position_conflict"] = $inspection['conflicts'];
            $details["{$prefix}_invalid_position"] = $inspection['invalid'];
        }

        $details['document_requirements_missing_position'] = $this->documentRequirementsMissingPosition($companyId)->all();
        $details['saved_views_unmapped_rank_filter'] = $this->savedViewsUnmappedRankFilters($companyId)->all();
        $details['saved_views_rank_position_conflict'] = $this->savedViewsRankPositionConflicts($companyId)->all();
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
            'document_requirements_missing_position' => 0,
            'saved_views_unmapped_rank_filter' => 0,
            'saved_views_rank_position_conflict' => 0,
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
