<?php

namespace App\Support\CrewOperations\Reset;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewAssignmentSequence;
use App\Models\CrewMovementCorrection;
use App\Models\CrewOperationalAlert;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class CrewOperationsResetAnalyzer
{
    public function analyze(?int $companyId = null): CrewOperationsResetPlan
    {
        $assignmentIds = $this->assignmentIds($companyId);
        $phaseIds = $this->phaseIds($assignmentIds);
        $crewTimesheetIds = $this->crewTimesheetIds($companyId);
        $eligiblePayrollRecordIds = $this->eligibleCrewPayrollRecordIds($companyId, $assignmentIds, $crewTimesheetIds);
        $companyIds = $this->resolveCompanyIds($companyId, $assignmentIds);

        $blockingIssues = $this->detectBlockingIssues($companyId, $assignmentIds);
        $warnings = [];

        $manualSeaServicePreserve = EmployeeSeaService::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereNull('crew_assignment_phase_id')
            ->count();

        $manualTrainingPreserve = EmployeeTraining::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereNull('source_crew_assignment_phase_id')
            ->count();

        $officePayrollPreserve = PayrollRecord::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where('payroll_category', PayrollCategory::Office)
            ->count();

        $crewPayrollPreserve = PayrollRecord::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where('payroll_category', PayrollCategory::Crew)
            ->whereNotIn('id', $eligiblePayrollRecordIds)
            ->count();

        $activityDelete = Activity::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(function (Builder $sub) use ($assignmentIds): void {
                $sub->where(function (Builder $inner) use ($assignmentIds): void {
                    $inner->where('subject_type', CrewAssignment::class)
                        ->whereIn('subject_id', $assignmentIds);
                })->orWhere(function (Builder $inner): void {
                    $inner->where('subject_type', CrewPlanningAssignment::class);
                });
            })
            ->count();

        $rows = [
            $this->row('payroll_work_allocations (crew-linked)', $this->countCrewLinkedAllocations($companyId, $assignmentIds, $crewTimesheetIds), 0, 'Crew operations payroll allocations only'),
            $this->row('payroll_records (eligible crew)', count($eligiblePayrollRecordIds), $crewPayrollPreserve, 'Manual or unrelated crew payroll preserved'),
            $this->row('crew_timesheet_segments', CrewTimesheetSegment::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_timesheets', count($crewTimesheetIds), 0, 'Includes soft-deleted rows'),
            $this->row('crew_timesheet_preparation_skips', CrewTimesheetPreparationSkip::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_timesheet_preparation_lines', CrewTimesheetPreparationLine::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_timesheet_preparations', CrewTimesheetPreparation::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_movement_corrections', CrewMovementCorrection::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_scheduled_movements', CrewScheduledMovement::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_accommodation_stays', CrewAccommodationStay::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('employee_trainings (assignment-generated)', EmployeeTraining::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->whereNotNull('source_crew_assignment_phase_id')->count(), $manualTrainingPreserve, 'Manual training preserved'),
            $this->row('employee_sea_services (assignment-synced)', EmployeeSeaService::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->whereNotNull('crew_assignment_phase_id')->count(), $manualSeaServicePreserve, 'Manual sea service preserved'),
            $this->row('historical_crew_import_rows', HistoricalCrewImportRow::query()->when($companyId !== null, fn (Builder $q) => $q->whereIn('historical_crew_import_batch_id', HistoricalCrewImportBatch::query()->where('company_id', $companyId)->select('id')))->count(), 0, ''),
            $this->row('crew_planning_assignments', CrewPlanningAssignment::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->withTrashed()->count(), 0, 'Operational planning rows only'),
            $this->row('crew_assignments', count($assignmentIds), 0, 'Includes voided and soft-deleted rows'),
            $this->row('crew_assignment_phases', count($phaseIds), 0, 'Removed via assignment cleanup'),
            $this->row('historical_crew_import_batches', HistoricalCrewImportBatch::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_operational_alerts (+ deliveries)', $this->operationalAlertDeleteCount($companyId), 0, 'Recipients and delivery rows included'),
            $this->row('activity_log (crew assignment & planning)', $activityDelete, 0, 'Assignment-related audit rows'),
            $this->row('payroll_records (office)', 0, $officePayrollPreserve, 'Never deleted by this command'),
            $this->row('employees / vessels / settings', 0, 1, 'Master data always preserved'),
        ];

        $sequenceRows = $this->sequenceRows($companyIds);

        return new CrewOperationsResetPlan(
            companyId: $companyId,
            rows: $rows,
            sequenceRows: $sequenceRows,
            blockingIssues: $blockingIssues,
            warnings: $warnings,
            assignmentIds: $assignmentIds,
            phaseIds: $phaseIds,
            crewTimesheetIds: $crewTimesheetIds,
            eligiblePayrollRecordIds: $eligiblePayrollRecordIds,
            companyIds: $companyIds,
        );
    }

    /**
     * @return list<int>
     */
    private function assignmentIds(?int $companyId): array
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
    private function phaseIds(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        return CrewAssignmentPhase::query()
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function crewTimesheetIds(?int $companyId): array
    {
        return CrewTimesheet::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->withTrashed()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $crewTimesheetIds
     * @return list<int>
     */
    private function eligibleCrewPayrollRecordIds(?int $companyId, array $assignmentIds, array $crewTimesheetIds): array
    {
        $ids = [];

        if ($assignmentIds !== [] || $crewTimesheetIds !== []) {
            $allocationQuery = PayrollWorkAllocation::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->whereNotNull('payroll_record_id')
                ->where(fn (Builder $inner) => $this->applyCrewLinkageScope($inner, $assignmentIds, $crewTimesheetIds));

            $ids = array_merge($ids, $allocationQuery->pluck('payroll_record_id')->map(fn ($id) => (int) $id)->all());
        }

        if ($crewTimesheetIds !== []) {
            $timesheetLinked = PayrollRecord::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->where('payroll_category', PayrollCategory::Crew)
                ->whereExists(function ($sub) use ($crewTimesheetIds): void {
                    $sub->from('crew_timesheets')
                        ->whereColumn('crew_timesheets.period_id', 'payroll_records.period_id')
                        ->whereColumn('crew_timesheets.employee_id', 'payroll_records.employee_id')
                        ->whereIn('crew_timesheets.id', $crewTimesheetIds);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ids = array_merge($ids, $timesheetLinked);
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return PayrollRecord::query()
            ->whereIn('id', $ids)
            ->where('payroll_category', PayrollCategory::Crew)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return list<int>
     */
    private function resolveCompanyIds(?int $companyId, array $assignmentIds): array
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
     * @return list<string>
     */
    private function detectBlockingIssues(?int $companyId, array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $issues = [];

        $officeAllocationCount = PayrollWorkAllocation::query()
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->whereNotNull('payroll_record_id')
            ->whereHas('payrollRecord', fn (Builder $q) => $q->where('payroll_category', PayrollCategory::Office))
            ->count();

        if ($officeAllocationCount > 0) {
            $issues[] = "Found {$officeAllocationCount} payroll work allocation(s) linking crew assignments to office payroll records.";
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

        return $issues;
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $crewTimesheetIds
     */
    private function countCrewLinkedAllocations(?int $companyId, array $assignmentIds, array $crewTimesheetIds): int
    {
        if ($assignmentIds === [] && $crewTimesheetIds === []) {
            return 0;
        }

        return PayrollWorkAllocation::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(fn (Builder $inner) => $this->applyCrewLinkageScope($inner, $assignmentIds, $crewTimesheetIds))
            ->count();
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $crewTimesheetIds
     */
    private function applyCrewLinkageScope(Builder $inner, array $assignmentIds, array $crewTimesheetIds): void
    {
        $started = false;

        if ($assignmentIds !== []) {
            $inner->whereIn('crew_assignment_id', $assignmentIds);
            $started = true;
        }

        if ($crewTimesheetIds !== []) {
            if ($started) {
                $inner->orWhereIn('crew_timesheet_id', $crewTimesheetIds);
            } else {
                $inner->whereIn('crew_timesheet_id', $crewTimesheetIds);
            }
        }
    }

    private function operationalAlertDeleteCount(?int $companyId): int
    {
        $alertIds = CrewOperationalAlert::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->pluck('id');

        if ($alertIds->isEmpty()) {
            return 0;
        }

        return (int) $alertIds->count()
            + (int) DB::table('crew_operational_alert_recipients')->whereIn('crew_operational_alert_id', $alertIds)->count()
            + (int) DB::table('crew_operational_alert_email_deliveries')->whereIn('crew_operational_alert_id', $alertIds)->count()
            + (int) DB::table('crew_operational_alert_push_deliveries')->whereIn('crew_operational_alert_id', $alertIds)->count();
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{company_id: int, company_name: string, year: int, current_last_number: int, next_assignment_no: string}>
     */
    private function sequenceRows(array $companyIds): array
    {
        $year = (int) config('crew_operations_reset.sequence_reset_year', 2026);
        $rows = [];

        foreach ($companyIds as $companyId) {
            $sequence = CrewAssignmentSequence::query()
                ->where('company_id', $companyId)
                ->where('year', $year)
                ->first();

            $lastNumber = (int) ($sequence?->last_number ?? 0);
            $companyName = (string) Company::query()->whereKey($companyId)->value('name');

            $rows[] = [
                'company_id' => $companyId,
                'company_name' => $companyName,
                'year' => $year,
                'current_last_number' => $lastNumber,
                'next_assignment_no' => sprintf('CA-%d-%06d', $year, 1),
            ];
        }

        if ($rows === [] && $companyIds === []) {
            $rows[] = [
                'company_id' => 0,
                'company_name' => '(no companies in scope)',
                'year' => $year,
                'current_last_number' => 0,
                'next_assignment_no' => sprintf('CA-%d-%06d', $year, 1),
            ];
        }

        return $rows;
    }

    /**
     * @return array{entity: string, delete: int, preserve: int, notes: string}
     */
    private function row(string $entity, int $delete, int $preserve, string $notes): array
    {
        return [
            'entity' => $entity,
            'delete' => $delete,
            'preserve' => $preserve,
            'notes' => $notes,
        ];
    }
}
