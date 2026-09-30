<?php

namespace App\Support\MasterData;

use App\Enums\RankPositionMatchType;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\DocumentRequirement;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\Rank;
use App\Models\RankPositionMapping;
use App\Models\VesselManning;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PrepareRankPositionConsolidation
{
    /**
     * @return list<array<string, mixed>>
     */
    public function run(?int $companyId = null, bool $apply = false): array
    {
        if ($companyId !== null) {
            $company = Company::query()->find($companyId);

            if ($company === null) {
                throw new InvalidArgumentException("Company [{$companyId}] was not found.");
            }

            $companies = collect([$company]);
        } else {
            $companies = Company::query()->orderBy('id')->get(['id', 'name']);
        }

        $reports = [];

        foreach ($companies as $company) {
            if ($apply) {
                $reports[] = DB::transaction(fn (): array => $this->processCompany($company, true));
            } else {
                $reports[] = $this->processCompany($company, false);
            }
        }

        return $reports;
    }

    /**
     * @return array<string, mixed>
     */
    private function processCompany(Company $company, bool $apply): array
    {
        $companyId = (int) $company->id;
        $report = $this->emptyReport($company);

        $existingMappings = RankPositionMapping::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('rank_id');

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'title', 'status', 'is_crew_position', 'max_tour_of_duty_days', 'deleted_at']);

        $positionsByNormalized = $positions
            ->groupBy(fn (Position $position): string => $this->normalizeTitle((string) $position->title));

        $ranks = $this->ranksForCompany($companyId);
        $report['active_ranks_considered'] = $ranks->filter(fn (Rank $rank): bool => $rank->is_active && $rank->deleted_at === null)->count();
        $report['ranks_considered'] = $ranks->count();

        /** @var array<int, int> $rankToPosition */
        $rankToPosition = [];
        /** @var array<int, true> $skipRecreationRankIds */
        $skipRecreationRankIds = [];

        foreach ($existingMappings as $rankId => $mapping) {
            $mappedPositionId = (int) $mapping->position_id;
            $position = Position::withTrashed()
                ->whereKey($mappedPositionId)
                ->first(['id', 'company_id', 'title', 'status', 'is_crew_position', 'max_tour_of_duty_days', 'deleted_at']);

            if ($position === null) {
                $report['integrity_failures'][] = [
                    'type' => 'mapping_position_missing',
                    'company_id' => $companyId,
                    'rank_id' => (int) $rankId,
                    'position_id' => $mappedPositionId,
                ];
                $skipRecreationRankIds[(int) $rankId] = true;

                continue;
            }

            if ((int) $position->company_id !== $companyId) {
                $report['integrity_failures'][] = [
                    'type' => 'mapping_position_company_mismatch',
                    'company_id' => $companyId,
                    'rank_id' => (int) $rankId,
                    'position_id' => (int) $position->id,
                    'position_company_id' => (int) $position->company_id,
                ];
                $skipRecreationRankIds[(int) $rankId] = true;

                continue;
            }

            if ($position->trashed()) {
                $detail = [
                    'type' => 'mapped_position_soft_deleted',
                    'company_id' => $companyId,
                    'rank_id' => (int) $rankId,
                    'position_id' => (int) $position->id,
                    'position_title' => $position->title,
                ];
                $report['integrity_failures'][] = $detail;
                $report['mapped_soft_deleted_positions']++;
                $report['mapped_soft_deleted_details'][] = $detail;
                // Unusable mapping: never recreate, never backfill this Position ID.
                $skipRecreationRankIds[(int) $rankId] = true;

                continue;
            }

            $rankToPosition[(int) $rankId] = (int) $position->id;
        }

        foreach ($ranks as $rank) {
            $rankId = (int) $rank->id;

            if (isset($skipRecreationRankIds[$rankId])) {
                // Broken mapping already exists — never create a duplicate Position/mapping.
                continue;
            }

            if (isset($rankToPosition[$rankId])) {
                $report['mappings_already_present']++;
                $mappedPosition = Position::withTrashed()->find($rankToPosition[$rankId]);
                if ($mappedPosition !== null && ! $mappedPosition->trashed()) {
                    $this->recordStatusConflict($rank, $mappedPosition, $report);
                }

                continue;
            }

            $normalized = $this->normalizeTitle((string) $rank->name);
            $candidates = $positionsByNormalized->get($normalized, collect());

            if ($candidates->count() > 1) {
                $report['ambiguous_normalized_matches']++;
                $report['ambiguous_matches'][] = [
                    'rank_id' => $rankId,
                    'rank_name' => $rank->name,
                    'normalized' => $normalized,
                    'position_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'position_titles' => $candidates->pluck('title')->values()->all(),
                ];

                continue;
            }

            if ($candidates->count() === 1) {
                /** @var Position $position */
                $position = $candidates->first();
                $report['existing_exact_matches']++;

                $todConflict = $this->tourOfDutyConflict($rank, $position);
                if ($todConflict !== null) {
                    $report['tod_conflicts']++;
                    $report['tod_conflict_details'][] = $todConflict;
                }

                $this->recordStatusConflict($rank, $position, $report);

                if ($apply) {
                    $this->applyExactMatch($rank, $position, $todConflict === null);
                    $positionsByNormalized = $this->refreshNormalizedIndex($companyId);
                    $positions = Position::query()->where('company_id', $companyId)->get([
                        'id', 'company_id', 'title', 'status', 'is_crew_position', 'max_tour_of_duty_days', 'deleted_at',
                    ]);
                }

                $rankToPosition[$rankId] = (int) $position->id;
                $report['mappings_created']++;

                continue;
            }

            $report['new_positions_required']++;

            if ($apply) {
                $position = $this->createPositionFromRank($companyId, $rank);
                $rankToPosition[$rankId] = (int) $position->id;
                $positions->push($position);
                $positionsByNormalized = $this->refreshNormalizedIndex($companyId);
            } else {
                // Dry-run placeholder id — never written.
                $rankToPosition[$rankId] = -1 * $rankId;
            }

            $report['mappings_created']++;
        }

        $report['near_duplicates'] = $this->nearDuplicateCandidates($ranks, $positions);
        $report['near_duplicate_count'] = count($report['near_duplicates']);

        $this->backfillEmployees($companyId, $rankToPosition, $positions, $apply, $report);
        $this->backfillCrewAssignments($companyId, $rankToPosition, $apply, $report);
        $this->backfillCrewPlanning($companyId, $rankToPosition, $apply, $report);
        $this->backfillSeaServices($companyId, $rankToPosition, $apply, $report);
        $this->backfillVesselManning($companyId, $rankToPosition, $apply, $report);
        $this->backfillDocumentRequirements($companyId, $rankToPosition, $apply, $report);
        $this->assertTenantIntegrity($companyId, $apply, $report);
        $this->appendCleanupReadiness($companyId, $report);

        return $report;
    }

    /**
     * @return Collection<int, Rank>
     */
    private function ranksForCompany(int $companyId): Collection
    {
        $referencedRankIds = collect()
            ->merge(Employee::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id')->distinct()->pluck('rank_id'))
            ->merge(CrewAssignment::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id')->distinct()->pluck('rank_id'))
            ->merge(CrewPlanningAssignment::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id')->distinct()->pluck('rank_id'))
            ->merge(EmployeeSeaService::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id')->distinct()->pluck('rank_id'))
            ->merge(VesselManning::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id')->distinct()->pluck('rank_id'))
            ->merge(
                DB::table('document_requirement_rank')
                    ->join('document_requirements', 'document_requirements.id', '=', 'document_requirement_rank.document_requirement_id')
                    ->where('document_requirements.company_id', $companyId)
                    ->distinct()
                    ->pluck('document_requirement_rank.rank_id')
            )
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        return Rank::withTrashed()
            ->where(function ($query) use ($referencedRankIds): void {
                $query->where(function ($active): void {
                    $active->whereNull('deleted_at')->where('is_active', true);
                });

                if ($referencedRankIds->isNotEmpty()) {
                    $query->orWhereIn('id', $referencedRankIds->all());
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function applyExactMatch(Rank $rank, Position $position, bool $canCopyTourOfDuty): void
    {
        $updates = ['is_crew_position' => true];

        if ($canCopyTourOfDuty && $position->max_tour_of_duty_days === null && $rank->max_tour_of_duty_days !== null) {
            $updates['max_tour_of_duty_days'] = $rank->max_tour_of_duty_days;
        }

        $position->fill($updates)->save();

        RankPositionMapping::query()->firstOrCreate(
            [
                'company_id' => (int) $position->company_id,
                'rank_id' => (int) $rank->id,
            ],
            [
                'position_id' => (int) $position->id,
                'match_type' => RankPositionMatchType::Exact,
            ],
        );
    }

    private function createPositionFromRank(int $companyId, Rank $rank): Position
    {
        $isSelectable = $rank->deleted_at === null && $rank->is_active;

        $position = Position::query()->create([
            'company_id' => $companyId,
            'department_id' => null,
            'title' => $rank->name,
            'status' => $isSelectable ? 'active' : 'inactive',
            'is_crew_position' => true,
            'max_tour_of_duty_days' => $rank->max_tour_of_duty_days,
        ]);

        RankPositionMapping::query()->create([
            'company_id' => $companyId,
            'rank_id' => (int) $rank->id,
            'position_id' => (int) $position->id,
            'match_type' => RankPositionMatchType::Created,
        ]);

        return $position;
    }

    /**
     * @param  array<int, int>  $rankToPosition
     * @param  Collection<int, Position>  $positions
     * @param  array<string, mixed>  $report
     */
    private function backfillEmployees(
        int $companyId,
        array $rankToPosition,
        Collection $positions,
        bool $apply,
        array &$report,
    ): void {
        $employees = Employee::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->get(['id', 'company_id', 'employee_no', 'name', 'rank_id', 'position_id', 'deleted_at']);

        $ranks = Rank::withTrashed()->whereIn('id', $employees->pluck('rank_id')->unique()->filter()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach ($employees as $employee) {
            $rankId = (int) $employee->rank_id;
            $mappedPositionId = $rankToPosition[$rankId] ?? null;

            if ($mappedPositionId === null) {
                $report['unmapped_references']++;
                $report['unmapped_reference_details'][] = [
                    'entity' => 'employee',
                    'id' => (int) $employee->id,
                    'rank_id' => $rankId,
                ];

                continue;
            }

            $persistedMappedId = $mappedPositionId > 0 ? $mappedPositionId : null;

            if ($employee->position_id === null) {
                $report['employees_backfilled']++;

                if ($apply && $persistedMappedId !== null) {
                    $employee->forceFill(['position_id' => $persistedMappedId])->save();
                }

                continue;
            }

            if ($persistedMappedId !== null && (int) $employee->position_id === $persistedMappedId) {
                $report['employees_already_consistent']++;

                continue;
            }

            // Dry-run created mappings use a synthetic id (< 1); treat any existing position as a conflict.
            if ($persistedMappedId === null) {
                $rank = $ranks->get($rankId);
                $currentPosition = $positions->firstWhere('id', (int) $employee->position_id)
                    ?? Position::query()->find((int) $employee->position_id);

                $report['employee_conflicts']++;
                $report['employee_conflict_details'][] = [
                    'employee_id' => (int) $employee->id,
                    'employee_number' => $employee->employee_no,
                    'employee_name' => $employee->name,
                    'company_id' => $companyId,
                    'current_position_id' => (int) $employee->position_id,
                    'current_position' => $currentPosition?->title,
                    'current_rank_id' => $rankId,
                    'current_rank' => $rank?->name,
                    'mapped_position_id' => null,
                    'mapped_position' => $rank?->name,
                    'note' => 'Rank would create a new Position; existing employee Position left unchanged.',
                ];

                continue;
            }

            $currentPosition = $positions->firstWhere('id', (int) $employee->position_id)
                ?? Position::query()->find((int) $employee->position_id);
            $mappedPosition = $positions->firstWhere('id', $persistedMappedId)
                ?? Position::query()->find($persistedMappedId);
            $rank = $ranks->get($rankId);

            $report['employee_conflicts']++;
            $report['employee_conflict_details'][] = [
                'employee_id' => (int) $employee->id,
                'employee_number' => $employee->employee_no,
                'employee_name' => $employee->name,
                'company_id' => $companyId,
                'current_position_id' => (int) $employee->position_id,
                'current_position' => $currentPosition?->title,
                'current_rank_id' => $rankId,
                'current_rank' => $rank?->name,
                'mapped_position_id' => $persistedMappedId,
                'mapped_position' => $mappedPosition?->title,
            ];
        }
    }

    /**
     * @param  array<int, int>  $rankToPosition
     * @param  array<string, mixed>  $report
     */
    private function backfillCrewAssignments(int $companyId, array $rankToPosition, bool $apply, array &$report): void
    {
        $this->backfillRankedRows(
            CrewAssignment::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id'),
            $companyId,
            $rankToPosition,
            $apply,
            $report,
            'crew_assignments_backfilled',
            'crew_assignment_conflicts',
            'crew_assignment',
        );
    }

    /**
     * @param  array<int, int>  $rankToPosition
     * @param  array<string, mixed>  $report
     */
    private function backfillCrewPlanning(int $companyId, array $rankToPosition, bool $apply, array &$report): void
    {
        $this->backfillRankedRows(
            CrewPlanningAssignment::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id'),
            $companyId,
            $rankToPosition,
            $apply,
            $report,
            'crew_planning_backfilled',
            'crew_planning_conflicts',
            'crew_planning',
        );
    }

    /**
     * @param  array<int, int>  $rankToPosition
     * @param  array<string, mixed>  $report
     */
    private function backfillSeaServices(int $companyId, array $rankToPosition, bool $apply, array &$report): void
    {
        $this->backfillRankedRows(
            EmployeeSeaService::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id'),
            $companyId,
            $rankToPosition,
            $apply,
            $report,
            'sea_services_backfilled',
            'sea_service_conflicts',
            'sea_service',
        );
    }

    /**
     * @param  array<int, int>  $rankToPosition
     * @param  array<string, mixed>  $report
     */
    private function backfillVesselManning(int $companyId, array $rankToPosition, bool $apply, array &$report): void
    {
        $this->backfillRankedRows(
            VesselManning::withTrashed()->where('company_id', $companyId)->whereNotNull('rank_id'),
            $companyId,
            $rankToPosition,
            $apply,
            $report,
            'vessel_manning_backfilled',
            'vessel_manning_conflicts',
            'vessel_manning',
        );
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, int>  $rankToPosition
     * @param  array<string, mixed>  $report
     */
    private function backfillRankedRows(
        $query,
        int $companyId,
        array $rankToPosition,
        bool $apply,
        array &$report,
        string $backfilledKey,
        string $conflictKey,
        string $entity,
    ): void {
        foreach ($query->get(['id', 'company_id', 'rank_id', 'position_id']) as $row) {
            $rankId = (int) $row->rank_id;
            $mappedPositionId = $rankToPosition[$rankId] ?? null;

            if ($mappedPositionId === null) {
                $report['unmapped_references']++;
                $report['unmapped_reference_details'][] = [
                    'entity' => $entity,
                    'id' => (int) $row->id,
                    'rank_id' => $rankId,
                    'company_id' => $companyId,
                ];

                continue;
            }

            $persistedMappedId = $mappedPositionId > 0 ? $mappedPositionId : null;

            if ($row->position_id === null) {
                $report[$backfilledKey]++;

                if ($apply && $persistedMappedId !== null) {
                    $row->forceFill(['position_id' => $persistedMappedId])->save();
                }

                continue;
            }

            if ($persistedMappedId !== null && (int) $row->position_id === $persistedMappedId) {
                continue;
            }

            $report[$conflictKey]++;
            $report['conflict_details'][] = [
                'entity' => $entity,
                'id' => (int) $row->id,
                'company_id' => $companyId,
                'rank_id' => $rankId,
                'current_position_id' => (int) $row->position_id,
                'mapped_position_id' => $persistedMappedId,
            ];
        }
    }

    /**
     * @param  array<int, int>  $rankToPosition
     * @param  array<string, mixed>  $report
     */
    private function backfillDocumentRequirements(int $companyId, array $rankToPosition, bool $apply, array &$report): void
    {
        $rows = DB::table('document_requirement_rank')
            ->join('document_requirements', 'document_requirements.id', '=', 'document_requirement_rank.document_requirement_id')
            ->where('document_requirements.company_id', $companyId)
            ->select([
                'document_requirement_rank.document_requirement_id',
                'document_requirement_rank.rank_id',
            ])
            ->get();

        foreach ($rows as $row) {
            $rankId = (int) $row->rank_id;
            $mappedPositionId = $rankToPosition[$rankId] ?? null;

            if ($mappedPositionId === null) {
                $report['unmapped_references']++;
                $report['unmapped_reference_details'][] = [
                    'entity' => 'document_requirement_rank',
                    'document_requirement_id' => (int) $row->document_requirement_id,
                    'rank_id' => $rankId,
                    'company_id' => $companyId,
                ];

                continue;
            }

            $persistedMappedId = $mappedPositionId > 0 ? $mappedPositionId : null;

            if ($persistedMappedId === null) {
                // Dry-run: Rank would create a Position, then copy the requirement.
                $report['document_rank_requirements_copied']++;

                continue;
            }

            $exists = DB::table('document_requirement_position')
                ->where('document_requirement_id', $row->document_requirement_id)
                ->where('position_id', $persistedMappedId)
                ->exists();

            if ($exists) {
                continue;
            }

            $report['document_rank_requirements_copied']++;

            if ($apply) {
                DocumentRequirement::query()
                    ->whereKey((int) $row->document_requirement_id)
                    ->where('company_id', $companyId)
                    ->first()
                    ?->positions()
                    ->syncWithoutDetaching([$persistedMappedId]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function assertTenantIntegrity(int $companyId, bool $apply, array &$report): void
    {
        $mappings = RankPositionMapping::query()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'rank_id', 'position_id']);

        foreach ($mappings as $mapping) {
            $position = Position::withTrashed()->find((int) $mapping->position_id);

            if ($position === null) {
                $this->pushUniqueIntegrityFailure($report, [
                    'type' => 'mapping_position_missing',
                    'company_id' => $companyId,
                    'rank_id' => (int) $mapping->rank_id,
                    'position_id' => (int) $mapping->position_id,
                ]);

                continue;
            }

            if ((int) $position->company_id !== $companyId) {
                $this->pushUniqueIntegrityFailure($report, [
                    'type' => 'mapping_position_company_mismatch',
                    'company_id' => $companyId,
                    'rank_id' => (int) $mapping->rank_id,
                    'position_id' => (int) $position->id,
                    'position_company_id' => (int) $position->company_id,
                ]);

                continue;
            }

            if ($position->trashed()) {
                $this->pushUniqueIntegrityFailure($report, [
                    'type' => 'mapped_position_soft_deleted',
                    'company_id' => $companyId,
                    'rank_id' => (int) $mapping->rank_id,
                    'position_id' => (int) $position->id,
                    'position_title' => $position->title,
                ]);
            }
        }

        if (! $apply) {
            return;
        }

        foreach ([
            'crew_assignments' => CrewAssignment::withTrashed(),
            'crew_planning_assignments' => CrewPlanningAssignment::withTrashed(),
            'employee_sea_services' => EmployeeSeaService::withTrashed(),
            'vessel_manning' => VesselManning::withTrashed(),
        ] as $label => $query) {
            $invalid = (clone $query)
                ->where("{$query->getModel()->getTable()}.company_id", $companyId)
                ->whereNotNull('position_id')
                ->whereHas('position', fn ($positionQuery) => $positionQuery->where('company_id', '!=', $companyId))
                ->count();

            if ($invalid > 0) {
                $report['integrity_failures'][] = [
                    'type' => 'backfilled_position_company_mismatch',
                    'table' => $label,
                    'company_id' => $companyId,
                    'count' => $invalid,
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $failure
     */
    private function pushUniqueIntegrityFailure(array &$report, array $failure): void
    {
        foreach ($report['integrity_failures'] as $existing) {
            if (($existing['type'] ?? null) === ($failure['type'] ?? null)
                && (int) ($existing['rank_id'] ?? 0) === (int) ($failure['rank_id'] ?? 0)
                && (int) ($existing['position_id'] ?? 0) === (int) ($failure['position_id'] ?? 0)
            ) {
                return;
            }
        }

        $report['integrity_failures'][] = $failure;

        if (($failure['type'] ?? null) === 'mapped_position_soft_deleted') {
            foreach ($report['mapped_soft_deleted_details'] as $existing) {
                if ((int) ($existing['rank_id'] ?? 0) === (int) ($failure['rank_id'] ?? 0)
                    && (int) ($existing['position_id'] ?? 0) === (int) ($failure['position_id'] ?? 0)
                ) {
                    return;
                }
            }

            $report['mapped_soft_deleted_positions']++;
            $report['mapped_soft_deleted_details'][] = $failure;
        }
    }

    /**
     * @param  Collection<int, Rank>  $ranks
     * @param  Collection<int, Position>  $positions
     * @return list<array<string, mixed>>
     */
    private function nearDuplicateCandidates(Collection $ranks, Collection $positions): array
    {
        $candidates = [];
        $seen = [];

        foreach ($ranks as $rank) {
            $rankNormalized = $this->normalizeTitle((string) $rank->name);

            foreach ($positions as $position) {
                $positionNormalized = $this->normalizeTitle((string) $position->title);

                if ($rankNormalized === $positionNormalized) {
                    continue;
                }

                if (! $this->looksLikeNearDuplicate($rankNormalized, $positionNormalized)) {
                    continue;
                }

                $key = (int) $rank->id.':'.(int) $position->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $candidates[] = [
                    'rank_id' => (int) $rank->id,
                    'rank_name' => $rank->name,
                    'position_id' => (int) $position->id,
                    'position_title' => $position->title,
                    'note' => 'Possible near-duplicate — manual review required',
                ];
            }
        }

        return $candidates;
    }

    private function looksLikeNearDuplicate(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        if (str_contains($left, $right) || str_contains($right, $left)) {
            return true;
        }

        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        if ($this->tokenJaccard($leftTokens, $rightTokens) >= 0.5) {
            return true;
        }

        if ($this->abbreviationMatch($left, $leftTokens, $right, $rightTokens)
            || $this->abbreviationMatch($right, $rightTokens, $left, $leftTokens)) {
            return true;
        }

        return $this->similarTokenSets($leftTokens, $rightTokens);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $parts = preg_split('/[\s\-_\/]+/u', $normalized) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function tokenJaccard(array $left, array $right): float
    {
        $leftSet = array_values(array_unique($left));
        $rightSet = array_values(array_unique($right));
        $intersection = count(array_intersect($leftSet, $rightSet));
        $union = count(array_unique([...$leftSet, ...$rightSet]));

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * @param  list<string>  $shortTokens
     * @param  list<string>  $longTokens
     */
    private function abbreviationMatch(string $short, array $shortTokens, string $long, array $longTokens): bool
    {
        if (count($longTokens) < 2) {
            return false;
        }

        $compactShort = str_replace([' ', '-', '_', '/'], '', $short);
        if (mb_strlen($compactShort) < 3) {
            return false;
        }

        $initials = implode('', array_map(
            static fn (string $token): string => mb_substr($token, 0, 1),
            $longTokens,
        ));

        $firstPlusRestInitials = $longTokens[0].implode('', array_map(
            static fn (string $token): string => mb_substr($token, 0, 1),
            array_slice($longTokens, 1),
        ));

        $compactLong = str_replace([' ', '-', '_', '/'], '', $long);

        return $compactShort === $initials
            || $compactShort === $firstPlusRestInitials
            || (
                count($shortTokens) === 1
                && str_starts_with($longTokens[0], $compactShort)
            )
            || (
                count($shortTokens) === 1
                && str_starts_with($compactLong, $compactShort)
                && mb_strlen($compactShort) >= 3
                && mb_strlen($compactShort) <= mb_strlen($longTokens[0]) + 1
            );
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function similarTokenSets(array $left, array $right): bool
    {
        if (count($left) !== count($right) || count($left) < 2) {
            return false;
        }

        $remainingRight = $right;
        $fuzzyPairs = 0;
        $exactPairs = 0;

        foreach ($left as $leftToken) {
            $bestIndex = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($remainingRight as $index => $rightToken) {
                if ($leftToken === $rightToken) {
                    $bestIndex = $index;
                    $bestDistance = 0;
                    break;
                }

                $distance = levenshtein($leftToken, $rightToken);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                return false;
            }

            $matched = $remainingRight[$bestIndex];
            unset($remainingRight[$bestIndex]);

            if ($leftToken === $matched) {
                $exactPairs++;

                continue;
            }

            $minLen = min(mb_strlen($leftToken), mb_strlen($matched));
            $maxAllowed = $minLen >= 6 ? 3 : 2;
            if ($minLen < 5 || $bestDistance > $maxAllowed) {
                return false;
            }

            $fuzzyPairs++;
        }

        return $exactPairs >= 1 && $fuzzyPairs >= 1;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function recordStatusConflict(Rank $rank, Position $position, array &$report): void
    {
        $rankActive = $rank->deleted_at === null && (bool) $rank->is_active;
        $positionActive = $position->deleted_at === null && $position->status === 'active';

        if (! $rankActive || $positionActive) {
            return;
        }

        foreach ($report['status_conflict_details'] as $existing) {
            if ((int) ($existing['rank_id'] ?? 0) === (int) $rank->id
                && (int) ($existing['position_id'] ?? 0) === (int) $position->id
            ) {
                return;
            }
        }

        $report['status_conflicts']++;
        $report['status_conflict_details'][] = [
            'rank_id' => (int) $rank->id,
            'rank_name' => $rank->name,
            'rank_active' => true,
            'position_id' => (int) $position->id,
            'position_title' => $position->title,
            'position_status' => $position->status,
        ];
    }

    private function tourOfDutyConflict(Rank $rank, Position $position): ?array
    {
        if (
            $position->max_tour_of_duty_days === null
            || $rank->max_tour_of_duty_days === null
            || (int) $position->max_tour_of_duty_days === (int) $rank->max_tour_of_duty_days
        ) {
            return null;
        }

        return [
            'rank_id' => (int) $rank->id,
            'rank_name' => $rank->name,
            'rank_max_tour_of_duty_days' => (int) $rank->max_tour_of_duty_days,
            'position_id' => (int) $position->id,
            'position_title' => $position->title,
            'position_max_tour_of_duty_days' => (int) $position->max_tour_of_duty_days,
        ];
    }

    /**
     * @return Collection<string, Collection<int, Position>>
     */
    private function refreshNormalizedIndex(int $companyId): Collection
    {
        return Position::query()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'title', 'status', 'is_crew_position', 'max_tour_of_duty_days', 'deleted_at'])
            ->groupBy(fn (Position $position): string => $this->normalizeTitle((string) $position->title));
    }

    public function normalizeTitle(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($collapsed);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(Company $company): array
    {
        return [
            'company_id' => (int) $company->id,
            'company_name' => $company->name,
            'active_ranks_considered' => 0,
            'ranks_considered' => 0,
            'existing_exact_matches' => 0,
            'new_positions_required' => 0,
            'mappings_created' => 0,
            'mappings_already_present' => 0,
            'employees_backfilled' => 0,
            'employees_already_consistent' => 0,
            'employee_conflicts' => 0,
            'employee_conflict_details' => [],
            'crew_assignments_backfilled' => 0,
            'crew_assignment_conflicts' => 0,
            'crew_planning_backfilled' => 0,
            'crew_planning_conflicts' => 0,
            'sea_services_backfilled' => 0,
            'sea_service_conflicts' => 0,
            'vessel_manning_backfilled' => 0,
            'vessel_manning_conflicts' => 0,
            'document_rank_requirements_copied' => 0,
            'ambiguous_normalized_matches' => 0,
            'ambiguous_matches' => [],
            'tod_conflicts' => 0,
            'tod_conflict_details' => [],
            'status_conflicts' => 0,
            'status_conflict_details' => [],
            'mapped_soft_deleted_positions' => 0,
            'mapped_soft_deleted_details' => [],
            'near_duplicate_count' => 0,
            'near_duplicates' => [],
            'unmapped_references' => 0,
            'unmapped_reference_details' => [],
            'conflict_details' => [],
            'integrity_failures' => [],
            'employees_rank_without_position' => 0,
            'crew_assignments_rank_without_position' => 0,
            'crew_planning_rank_without_position' => 0,
            'sea_services_rank_without_position' => 0,
            'vessel_manning_rank_without_position' => 0,
            'document_rank_requirements_missing_position' => 0,
            'ready_for_rank_cleanup' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function appendCleanupReadiness(int $companyId, array &$report): void
    {
        $report['employees_rank_without_position'] = Employee::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->count();

        $report['crew_assignments_rank_without_position'] = CrewAssignment::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->count();

        $report['crew_planning_rank_without_position'] = CrewPlanningAssignment::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->count();

        $report['sea_services_rank_without_position'] = EmployeeSeaService::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->count();

        $report['vessel_manning_rank_without_position'] = VesselManning::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->count();

        $report['document_rank_requirements_missing_position'] = 0;

        $rankRequirementRows = DB::table('document_requirement_rank')
            ->join('document_requirements', 'document_requirements.id', '=', 'document_requirement_rank.document_requirement_id')
            ->where('document_requirements.company_id', $companyId)
            ->select([
                'document_requirement_rank.document_requirement_id',
                'document_requirement_rank.rank_id',
            ])
            ->get();

        foreach ($rankRequirementRows as $row) {
            $mappedPositionId = RankPositionMapping::query()
                ->where('company_id', $companyId)
                ->where('rank_id', (int) $row->rank_id)
                ->value('position_id');

            if ($mappedPositionId === null) {
                $report['document_rank_requirements_missing_position']++;

                continue;
            }

            $hasPositionRequirement = DB::table('document_requirement_position')
                ->where('document_requirement_id', (int) $row->document_requirement_id)
                ->where('position_id', (int) $mappedPositionId)
                ->exists();

            if (! $hasPositionRequirement) {
                $report['document_rank_requirements_missing_position']++;
            }
        }

        $report['ready_for_rank_cleanup'] = ($report['integrity_failures'] ?? []) === []
            && (int) $report['mapped_soft_deleted_positions'] === 0
            && (int) $report['status_conflicts'] === 0
            && (int) $report['employee_conflicts'] === 0
            && (int) $report['tod_conflicts'] === 0
            && (int) $report['ambiguous_normalized_matches'] === 0
            && (int) $report['unmapped_references'] === 0
            && (int) $report['employees_rank_without_position'] === 0
            && (int) $report['crew_assignments_rank_without_position'] === 0
            && (int) $report['crew_planning_rank_without_position'] === 0
            && (int) $report['sea_services_rank_without_position'] === 0
            && (int) $report['vessel_manning_rank_without_position'] === 0
            && (int) $report['document_rank_requirements_missing_position'] === 0;
    }
}
