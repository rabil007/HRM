<?php

namespace App\Support\MasterData;

use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\SavedView;
use App\Models\VesselManning;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only Rank-removal readiness checks for Phase 3B.
 */
final class RankRemovalReadiness
{
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

        $ready = collect($totals)->every(fn (int $count): bool => $count === 0);

        return [
            'ready' => $ready,
            'already_removed' => false,
            'companies' => $companyReports,
            'totals' => $totals,
        ];
    }

    /**
     * @return array{company_id: int, company_name: string, ready: bool, counts: array<string, int>, details: array<string, list<mixed>>}
     */
    public function forCompany(int $companyId, string $companyName = ''): array
    {
        $counts = [
            'employees_missing_position' => $this->countMissingPosition(Employee::class, $companyId),
            'crew_assignments_missing_position' => $this->countMissingPosition(CrewAssignment::class, $companyId),
            'crew_planning_assignments_missing_position' => $this->countMissingPosition(CrewPlanningAssignment::class, $companyId),
            'employee_sea_services_missing_position' => $this->countMissingPosition(EmployeeSeaService::class, $companyId),
            'vessel_manning_missing_position' => $this->countMissingPosition(VesselManning::class, $companyId),
            'document_requirements_missing_position' => $this->documentRequirementsMissingPosition($companyId)->count(),
            'saved_views_unmapped_rank_filter' => $this->savedViewsUnmappedRankFilters($companyId)->count(),
            'broken_mappings' => $this->brokenMappings($companyId)->count(),
        ];

        return [
            'company_id' => $companyId,
            'company_name' => $companyName,
            'ready' => collect($counts)->every(fn (int $count): bool => $count === 0),
            'counts' => $counts,
            'details' => [
                'document_requirement_ids' => $this->documentRequirementsMissingPosition($companyId)->all(),
                'saved_view_ids' => $this->savedViewsUnmappedRankFilters($companyId)->pluck('id')->all(),
                'broken_mapping_ids' => $this->brokenMappings($companyId)->pluck('id')->all(),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyTotals(): array
    {
        return [
            'employees_missing_position' => 0,
            'crew_assignments_missing_position' => 0,
            'crew_planning_assignments_missing_position' => 0,
            'employee_sea_services_missing_position' => 0,
            'vessel_manning_missing_position' => 0,
            'document_requirements_missing_position' => 0,
            'saved_views_unmapped_rank_filter' => 0,
            'broken_mappings' => 0,
        ];
    }

    /**
     * @param  class-string  $model
     */
    private function countMissingPosition(string $model, int $companyId): int
    {
        $table = (new $model)->getTable();

        if (! Schema::hasColumn($table, 'rank_id') || ! Schema::hasColumn($table, 'position_id')) {
            return 0;
        }

        return $model::query()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->count();
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

        $missing = collect();

        foreach ($rows as $row) {
            $mappedPositionId = null;

            if (Schema::hasTable('rank_position_mappings')) {
                $mappedPositionId = DB::table('rank_position_mappings')
                    ->where('company_id', $companyId)
                    ->where('rank_id', (int) $row->rank_id)
                    ->value('position_id');
            }

            if ($mappedPositionId === null) {
                $missing->push((int) $row->document_requirement_id);

                continue;
            }

            $hasPositionPivot = DB::table('document_requirement_position')
                ->where('document_requirement_id', (int) $row->document_requirement_id)
                ->where('position_id', (int) $mappedPositionId)
                ->exists();

            if (! $hasPositionPivot) {
                $missing->push((int) $row->document_requirement_id);
            }
        }

        return $missing->unique()->values();
    }

    /**
     * @return Collection<int, SavedView>
     */
    private function savedViewsUnmappedRankFilters(int $companyId): Collection
    {
        return SavedView::query()
            ->where('company_id', $companyId)
            ->get()
            ->filter(function (SavedView $view) use ($companyId): bool {
                $filters = is_array($view->filters) ? $view->filters : [];

                if (! array_key_exists('rank_id', $filters)) {
                    return false;
                }

                $rankId = (int) $filters['rank_id'];

                if ($rankId < 1) {
                    return true;
                }

                if (! Schema::hasTable('rank_position_mappings')) {
                    return true;
                }

                $mapped = DB::table('rank_position_mappings')
                    ->where('company_id', $companyId)
                    ->where('rank_id', $rankId)
                    ->value('position_id');

                return $mapped === null;
            })
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
            ->where('rpm.company_id', $companyId)
            ->select([
                'rpm.id',
                'rpm.company_id',
                'rpm.rank_id',
                'rpm.position_id',
                'p.company_id as position_company_id',
                'p.deleted_at as position_deleted_at',
            ])
            ->get()
            ->filter(function (object $mapping): bool {
                if ($mapping->position_id === null || $mapping->position_company_id === null) {
                    return true;
                }

                if ($mapping->position_deleted_at !== null) {
                    return true;
                }

                return (int) $mapping->position_company_id !== (int) $mapping->company_id;
            })
            ->values();
    }
}
