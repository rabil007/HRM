<?php

namespace App\Support\CrewOperations\Reset;

use App\Enums\PayrollCategory;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewAssignmentSequence;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Models\SalaryInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewOperationsResetScope
{
    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $segmentIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $deletableAllocationIds
     * @param  list<int>  $eligiblePayrollRecordIds
     * @param  list<int>  $ambiguousCrewPayrollRecordIds
     * @param  list<int>  $preservedMixedAllocationPayrollRecordIds
     * @param  list<int>  $deletableSalaryInputIds
     * @param  list<int>  $preservedAmbiguousSalaryInputIds
     * @param  list<string>  $blockingIssues
     * @param  list<int>  $companyIds
     */
    public function __construct(
        public readonly ?int $companyId,
        public readonly array $assignmentIds,
        public readonly array $phaseIds,
        public readonly array $segmentIds,
        public readonly array $crewTimesheetIds,
        public readonly array $deletableAllocationIds,
        public readonly array $eligiblePayrollRecordIds,
        public readonly array $ambiguousCrewPayrollRecordIds,
        public readonly array $preservedMixedAllocationPayrollRecordIds,
        public readonly array $deletableSalaryInputIds,
        public readonly array $preservedAmbiguousSalaryInputIds,
        public readonly array $blockingIssues,
        public readonly array $companyIds,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockingIssues !== [];
    }

    public static function resolve(?int $companyId = null): self
    {
        $assignmentIds = self::assignmentIds($companyId);
        $phaseIds = self::phaseIds($assignmentIds);
        $crewTimesheetIds = self::crewTimesheetIds($companyId);
        $segmentIds = self::segmentIds($companyId, $crewTimesheetIds);

        $blockingIssues = self::detectBlockingIssues($companyId, $assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds);
        $strictDeletableAllocationIds = self::resolveStrictDeletableAllocationIds(
            $companyId,
            $assignmentIds,
            $phaseIds,
            $crewTimesheetIds,
            $segmentIds,
            $blockingIssues,
        );

        [
            'eligible_payroll_record_ids' => $eligiblePayrollRecordIds,
            'deletable_allocation_ids' => $deletableAllocationIds,
            'preserved_mixed_allocation_payroll_record_ids' => $preservedMixedAllocationPayrollRecordIds,
        ] = self::reconcilePayrollRecordsAndAllocations($strictDeletableAllocationIds);

        $ambiguousCrewPayrollRecordIds = self::ambiguousCrewPayrollRecordIds(
            $companyId,
            $crewTimesheetIds,
            $eligiblePayrollRecordIds,
        );

        $deletableSalaryInputIds = self::ownedSalaryInputIdsForPayrollRecords($eligiblePayrollRecordIds);
        $preservedAmbiguousSalaryInputIds = self::preservedAmbiguousSalaryInputIds(
            $companyId,
            $eligiblePayrollRecordIds,
            $deletableSalaryInputIds,
        );

        $companyIds = self::resolveCompanyIds($companyId, $assignmentIds);

        return new self(
            companyId: $companyId,
            assignmentIds: $assignmentIds,
            phaseIds: $phaseIds,
            segmentIds: $segmentIds,
            crewTimesheetIds: $crewTimesheetIds,
            deletableAllocationIds: $deletableAllocationIds,
            eligiblePayrollRecordIds: $eligiblePayrollRecordIds,
            ambiguousCrewPayrollRecordIds: $ambiguousCrewPayrollRecordIds,
            preservedMixedAllocationPayrollRecordIds: $preservedMixedAllocationPayrollRecordIds,
            deletableSalaryInputIds: $deletableSalaryInputIds,
            preservedAmbiguousSalaryInputIds: $preservedAmbiguousSalaryInputIds,
            blockingIssues: $blockingIssues,
            companyIds: $companyIds,
        );
    }

    /**
     * @return list<int>
     */
    private static function assignmentIds(?int $companyId): array
    {
        return CrewAssignment::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->withTrashed()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return list<int>
     */
    private static function phaseIds(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        return CrewAssignmentPhase::query()
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->withTrashed()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function crewTimesheetIds(?int $companyId): array
    {
        return CrewTimesheet::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->withTrashed()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $crewTimesheetIds
     * @return list<int>
     */
    private static function segmentIds(?int $companyId, array $crewTimesheetIds): array
    {
        if ($crewTimesheetIds === []) {
            return [];
        }

        return CrewTimesheetSegment::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereIn('crew_timesheet_id', $crewTimesheetIds)
            ->withTrashed()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $segmentIds
     * @return list<string>
     */
    private static function detectBlockingIssues(
        ?int $companyId,
        array $assignmentIds,
        array $phaseIds,
        array $crewTimesheetIds,
        array $segmentIds,
    ): array {
        $issues = [];

        if ($assignmentIds !== []) {
            $officeAllocationCount = PayrollWorkAllocation::query()
                ->where(function (Builder $inner) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
                    self::applyInScopeCrewLinkageOr($inner, $assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds);
                })
                ->whereNotNull('payroll_record_id')
                ->whereHas('payrollRecord', fn (Builder $q) => $q->where('payroll_category', PayrollCategory::Office))
                ->count();

            if ($officeAllocationCount > 0) {
                $issues[] = "Found {$officeAllocationCount} payroll work allocation(s) linking crew operations data to office payroll records.";
            }

            $foreignReliefCount = CrewAssignment::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', '!=', $companyId))
                ->where(function (Builder $inner) use ($assignmentIds): void {
                    $inner->whereIn('relieves_crew_assignment_id', $assignmentIds)
                        ->orWhereIn('previous_assignment_id', $assignmentIds);
                })
                ->count();

            if ($foreignReliefCount > 0) {
                $issues[] = "Found {$foreignReliefCount} crew assignment(s) outside the selected company scope referencing assignments targeted for deletion.";
            }

            $foreignPlanningCount = CrewPlanningAssignment::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', '!=', $companyId))
                ->where(function (Builder $inner) use ($assignmentIds): void {
                    $inner->whereIn('crew_assignment_id', $assignmentIds)
                        ->orWhereIn('relieves_crew_assignment_id', $assignmentIds);
                })
                ->withTrashed()
                ->count();

            if ($foreignPlanningCount > 0) {
                $issues[] = "Found {$foreignPlanningCount} crew planning assignment(s) outside the selected company scope referencing assignments targeted for deletion.";
            }
        }

        if ($companyId !== null && ($assignmentIds !== [] || $crewTimesheetIds !== [])) {
            $crossCompanyAllocationCount = PayrollWorkAllocation::query()
                ->where('company_id', '!=', $companyId)
                ->where(function (Builder $inner) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
                    self::applyInScopeCrewLinkageOr($inner, $assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds);
                })
                ->count();

            if ($crossCompanyAllocationCount > 0) {
                $issues[] = "Found {$crossCompanyAllocationCount} payroll work allocation(s) outside the selected company that reference crew operations rows in scope.";
            }
        }

        $ambiguousAllocationCount = self::countAmbiguousAllocations(
            $companyId,
            $assignmentIds,
            $phaseIds,
            $crewTimesheetIds,
            $segmentIds,
        );

        if ($ambiguousAllocationCount > 0) {
            $issues[] = "Found {$ambiguousAllocationCount} payroll work allocation(s) with mixed or out-of-scope crew operations references.";
        }

        return $issues;
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $segmentIds
     * @param  list<string>  $blockingIssues
     * @return list<int>
     */
    private static function resolveStrictDeletableAllocationIds(
        ?int $companyId,
        array $assignmentIds,
        array $phaseIds,
        array $crewTimesheetIds,
        array $segmentIds,
        array $blockingIssues,
    ): array {
        if ($blockingIssues !== []) {
            return [];
        }

        if ($assignmentIds === [] && $crewTimesheetIds === [] && $segmentIds === []) {
            return [];
        }

        return PayrollWorkAllocation::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(function (Builder $outer) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
                $outer->where(function (Builder $inner) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
                    self::applyStrictDeletableAllocationScope($inner, $assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds);
                });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $strictDeletableAllocationIds
     * @return array{
     *     eligible_payroll_record_ids: list<int>,
     *     deletable_allocation_ids: list<int>,
     *     preserved_mixed_allocation_payroll_record_ids: list<int>
     * }
     */
    private static function reconcilePayrollRecordsAndAllocations(array $strictDeletableAllocationIds): array
    {
        if ($strictDeletableAllocationIds === []) {
            return [
                'eligible_payroll_record_ids' => [],
                'deletable_allocation_ids' => [],
                'preserved_mixed_allocation_payroll_record_ids' => [],
            ];
        }

        $candidateRecordIds = PayrollWorkAllocation::query()
            ->whereIn('id', $strictDeletableAllocationIds)
            ->whereNotNull('payroll_record_id')
            ->pluck('payroll_record_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $eligibleRecordIds = [];
        $preservedMixedRecordIds = [];

        foreach ($candidateRecordIds as $recordId) {
            $allocationIdsForRecord = PayrollWorkAllocation::query()
                ->where('payroll_record_id', $recordId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $hasOutsideScopeAllocation = false;
            foreach ($allocationIdsForRecord as $allocationId) {
                if (! in_array($allocationId, $strictDeletableAllocationIds, true)) {
                    $hasOutsideScopeAllocation = true;
                    break;
                }
            }

            if ($hasOutsideScopeAllocation) {
                $preservedMixedRecordIds[] = $recordId;

                continue;
            }

            $eligibleRecordIds[] = $recordId;
        }

        $eligiblePayrollRecordIds = PayrollRecord::query()
            ->whereIn('id', $eligibleRecordIds)
            ->where('payroll_category', PayrollCategory::Crew)
            ->withTrashed()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $eligibleRecordIdSet = array_flip($eligiblePayrollRecordIds);

        $finalDeletableAllocationIds = array_values(array_filter(
            $strictDeletableAllocationIds,
            static function (int $allocationId) use ($eligibleRecordIdSet): bool {
                $payrollRecordId = PayrollWorkAllocation::query()
                    ->whereKey($allocationId)
                    ->value('payroll_record_id');

                if ($payrollRecordId === null) {
                    return true;
                }

                return isset($eligibleRecordIdSet[(int) $payrollRecordId]);
            },
        ));

        return [
            'eligible_payroll_record_ids' => $eligiblePayrollRecordIds,
            'deletable_allocation_ids' => $finalDeletableAllocationIds,
            'preserved_mixed_allocation_payroll_record_ids' => array_values(array_unique($preservedMixedRecordIds)),
        ];
    }

    /**
     * @param  list<int>  $eligiblePayrollRecordIds
     * @return list<int>
     */
    private static function ownedSalaryInputIdsForPayrollRecords(array $eligiblePayrollRecordIds): array
    {
        if ($eligiblePayrollRecordIds === []) {
            return [];
        }

        $candidateIds = [];

        PayrollRecord::query()
            ->whereIn('id', $eligiblePayrollRecordIds)
            ->get(['id', 'calculation_breakdown'])
            ->each(function (PayrollRecord $record) use (&$candidateIds): void {
                $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
                $rows = is_array($breakdown['salary_inputs'] ?? null) ? $breakdown['salary_inputs'] : [];

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    if (($row[CrewOperationsResetSalaryInputOwnership::BREAKDOWN_KEY] ?? false) !== true) {
                        continue;
                    }

                    $inputId = $row['id'] ?? null;
                    if ($inputId !== null && is_numeric($inputId)) {
                        $candidateIds[] = (int) $inputId;
                    }
                }
            });

        $candidateIds = array_values(array_unique($candidateIds));

        if ($candidateIds === []) {
            return [];
        }

        return SalaryInput::query()
            ->whereIn('id', $candidateIds)
            ->get(['id', CrewOperationsResetSalaryInputOwnership::COLUMN])
            ->filter(fn (SalaryInput $input) => CrewOperationsResetSalaryInputOwnership::isOwned($input))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Salary inputs sharing employee and period with eligible crew payroll that are not crew-operations-owned.
     *
     * @param  list<int>  $eligiblePayrollRecordIds
     * @param  list<int>  $deletableSalaryInputIds
     * @return list<int>
     */
    private static function preservedAmbiguousSalaryInputIds(
        ?int $companyId,
        array $eligiblePayrollRecordIds,
        array $deletableSalaryInputIds,
    ): array {
        if ($eligiblePayrollRecordIds === []) {
            return [];
        }

        return SalaryInput::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereExists(function ($sub) use ($eligiblePayrollRecordIds): void {
                $sub->from('payroll_records')
                    ->whereColumn('payroll_records.employee_id', 'salary_inputs.employee_id')
                    ->whereColumn('payroll_records.period_id', 'salary_inputs.period_id')
                    ->whereIn('payroll_records.id', $eligiblePayrollRecordIds);
            })
            ->when($deletableSalaryInputIds !== [], fn (Builder $q) => $q->whereNotIn('id', $deletableSalaryInputIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Crew payroll rows that share a period and employee with an in-scope timesheet but lack
     * crew-operations work allocations — treated as manual/ambiguous and preserved.
     *
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $eligiblePayrollRecordIds
     * @return list<int>
     */
    private static function ambiguousCrewPayrollRecordIds(
        ?int $companyId,
        array $crewTimesheetIds,
        array $eligiblePayrollRecordIds,
    ): array {
        if ($crewTimesheetIds === []) {
            return [];
        }

        return PayrollRecord::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where('payroll_category', PayrollCategory::Crew)
            ->when($eligiblePayrollRecordIds !== [], fn (Builder $q) => $q->whereNotIn('id', $eligiblePayrollRecordIds))
            ->whereExists(function ($sub) use ($crewTimesheetIds): void {
                $sub->from('crew_timesheets')
                    ->whereColumn('crew_timesheets.period_id', 'payroll_records.period_id')
                    ->whereColumn('crew_timesheets.employee_id', 'payroll_records.employee_id')
                    ->whereIn('crew_timesheets.id', $crewTimesheetIds);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return list<int>
     */
    private static function resolveCompanyIds(?int $companyId, array $assignmentIds): array
    {
        if ($companyId !== null) {
            return [$companyId];
        }

        $fromAssignments = CrewAssignment::query()
            ->withTrashed()
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $fromSequences = CrewAssignmentSequence::query()
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($fromAssignments, $fromSequences)));
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $segmentIds
     */
    private static function countAmbiguousAllocations(
        ?int $companyId,
        array $assignmentIds,
        array $phaseIds,
        array $crewTimesheetIds,
        array $segmentIds,
    ): int {
        if ($assignmentIds === [] && $crewTimesheetIds === [] && $segmentIds === []) {
            return 0;
        }

        return PayrollWorkAllocation::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(function (Builder $outer) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
                self::applyInScopeCrewLinkageOr($outer, $assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds);
            })
            ->whereNot(function (Builder $inner) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
                self::applyStrictDeletableAllocationScope($inner, $assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds);
            })
            ->count();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $segmentIds
     */
    private static function applyInScopeCrewLinkageOr(
        Builder $inner,
        array $assignmentIds,
        array $phaseIds,
        array $crewTimesheetIds,
        array $segmentIds,
    ): void {
        $started = false;

        if ($assignmentIds !== []) {
            $inner->whereIn('crew_assignment_id', $assignmentIds);
            $started = true;
        }

        if ($phaseIds !== []) {
            $started
                ? $inner->orWhereIn('crew_assignment_phase_id', $phaseIds)
                : $inner->whereIn('crew_assignment_phase_id', $phaseIds);
            $started = true;
        }

        if ($crewTimesheetIds !== []) {
            $started
                ? $inner->orWhereIn('crew_timesheet_id', $crewTimesheetIds)
                : $inner->whereIn('crew_timesheet_id', $crewTimesheetIds);
            $started = true;
        }

        if ($segmentIds !== []) {
            $started
                ? $inner->orWhereIn('crew_timesheet_segment_id', $segmentIds)
                : $inner->whereIn('crew_timesheet_segment_id', $segmentIds);
        }
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $segmentIds
     */
    private static function applyStrictDeletableAllocationScope(
        Builder $inner,
        array $assignmentIds,
        array $phaseIds,
        array $crewTimesheetIds,
        array $segmentIds,
    ): void {
        $inner->where(function (Builder $link) use ($assignmentIds, $phaseIds, $crewTimesheetIds, $segmentIds): void {
            $matched = false;

            if ($assignmentIds !== []) {
                $link->where(function (Builder $q) use ($assignmentIds): void {
                    $q->whereIn('crew_assignment_id', $assignmentIds);
                });
                $matched = true;
            }

            if ($phaseIds !== []) {
                $method = $matched ? 'orWhere' : 'where';
                $link->{$method}(function (Builder $q) use ($phaseIds): void {
                    $q->whereIn('crew_assignment_phase_id', $phaseIds);
                });
                $matched = true;
            }

            if ($segmentIds !== []) {
                $method = $matched ? 'orWhere' : 'where';
                $link->{$method}(function (Builder $q) use ($segmentIds): void {
                    $q->whereIn('crew_timesheet_segment_id', $segmentIds);
                });
                $matched = true;
            }

            if ($crewTimesheetIds !== []) {
                $method = $matched ? 'orWhere' : 'where';
                $link->{$method}(function (Builder $q) use ($crewTimesheetIds, $assignmentIds, $phaseIds, $segmentIds): void {
                    $q->whereIn('crew_timesheet_id', $crewTimesheetIds)
                        ->where(function (Builder $fk) use ($assignmentIds): void {
                            $fk->whereNull('crew_assignment_id')
                                ->orWhereIn('crew_assignment_id', $assignmentIds);
                        })
                        ->where(function (Builder $fk) use ($phaseIds): void {
                            $fk->whereNull('crew_assignment_phase_id')
                                ->orWhereIn('crew_assignment_phase_id', $phaseIds);
                        })
                        ->where(function (Builder $fk) use ($segmentIds): void {
                            $fk->whereNull('crew_timesheet_segment_id')
                                ->orWhereIn('crew_timesheet_segment_id', $segmentIds);
                        });
                });
            }
        });

        self::requireForeignKeysInScopeOrNull($inner, 'crew_assignment_id', $assignmentIds);
        self::requireForeignKeysInScopeOrNull($inner, 'crew_assignment_phase_id', $phaseIds);
        self::requireForeignKeysInScopeOrNull($inner, 'crew_timesheet_id', $crewTimesheetIds);
        self::requireForeignKeysInScopeOrNull($inner, 'crew_timesheet_segment_id', $segmentIds);
    }

    /**
     * @param  list<int>  $allowedIds
     */
    private static function requireForeignKeysInScopeOrNull(Builder $inner, string $column, array $allowedIds): void
    {
        $inner->where(function (Builder $fk) use ($column, $allowedIds): void {
            $fk->whereNull($column);
            if ($allowedIds !== []) {
                $fk->orWhereIn($column, $allowedIds);
            }
        });
    }

    public function countDeletableAllocations(): int
    {
        return count($this->deletableAllocationIds);
    }

    /**
     * @return Collection<int, PayrollRecord>
     */
    public function eligiblePayrollRecords(): Collection
    {
        if ($this->eligiblePayrollRecordIds === []) {
            return Collection::make();
        }

        return PayrollRecord::query()
            ->whereIn('id', $this->eligiblePayrollRecordIds)
            ->get(['id', 'employee_id', 'period_id']);
    }
}
