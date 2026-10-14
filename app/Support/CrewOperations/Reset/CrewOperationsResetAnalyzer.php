<?php

namespace App\Support\CrewOperations\Reset;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentSequence;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use App\Models\PayrollRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class CrewOperationsResetAnalyzer
{
    public function analyze(?int $companyId = null): CrewOperationsResetPlan
    {
        $scope = CrewOperationsResetScope::resolve($companyId);

        $warnings = [];
        if ($scope->ambiguousCrewPayrollRecordIds !== []) {
            $warnings[] = sprintf(
                '%d crew payroll record(s) share a period and employee with in-scope timesheets but have no crew-operations work allocations; they will be preserved.',
                count($scope->ambiguousCrewPayrollRecordIds),
            );
        }

        if ($scope->preservedMixedAllocationPayrollRecordIds !== []) {
            $warnings[] = sprintf(
                '%d crew payroll record(s) have work allocations outside the deletable scope; the full record and all of its allocations will be preserved.',
                count($scope->preservedMixedAllocationPayrollRecordIds),
            );
        }

        if ($scope->preservedAmbiguousSalaryInputIds !== []) {
            $warnings[] = sprintf(
                '%d salary input(s) share an employee and period with eligible crew payroll but are not owned by the crew operations import workflow; they will be preserved.',
                count($scope->preservedAmbiguousSalaryInputIds),
            );
        }

        $manualSeaServicePreserve = EmployeeSeaService::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereNull('crew_assignment_phase_id')
            ->count();

        $syncedSeaServiceDelete = EmployeeSeaService::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereNotNull('crew_assignment_phase_id')
            ->withTrashed()
            ->count();

        $manualTrainingPreserve = EmployeeTraining::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereNull('source_crew_assignment_phase_id')
            ->count();

        $officePayrollPreserve = PayrollRecord::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where('payroll_category', PayrollCategory::Office)
            ->withTrashed()
            ->count();

        $crewPayrollPreserve = PayrollRecord::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where('payroll_category', PayrollCategory::Crew)
            ->whereNotIn('id', $scope->eligiblePayrollRecordIds)
            ->withTrashed()
            ->count();

        $salaryInputDelete = count($scope->deletableSalaryInputIds);
        $salaryInputPreserve = count($scope->preservedAmbiguousSalaryInputIds);

        $activityDelete = Activity::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(function (Builder $sub) use ($scope, $companyId): void {
                $sub->where(function (Builder $inner) use ($scope): void {
                    $inner->where('subject_type', CrewAssignment::class)
                        ->whereIn('subject_id', $scope->assignmentIds);
                })->orWhere(function (Builder $inner) use ($companyId): void {
                    $inner->where('subject_type', CrewPlanningAssignment::class);
                    if ($companyId !== null) {
                        $inner->where('company_id', $companyId);
                    }
                });
            })
            ->count();

        $rows = [
            $this->row('payroll_work_allocations (crew-operations)', $scope->countDeletableAllocations(), 0, 'Strict in-scope crew linkage only'),
            $this->row('salary_inputs (crew operations owned)', $salaryInputDelete, $salaryInputPreserve, 'Manual or snapshotted-only inputs preserved'),
            $this->row('payroll_records (eligible crew)', count($scope->eligiblePayrollRecordIds), $crewPayrollPreserve, 'Manual or ambiguous crew payroll preserved'),
            $this->row('crew_timesheet_segments', $this->countSegments($companyId), 0, 'Includes soft-deleted rows'),
            $this->row('crew_timesheets', count($scope->crewTimesheetIds), 0, 'Includes soft-deleted rows'),
            $this->row('crew_timesheet_preparation_skips', CrewTimesheetPreparationSkip::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_timesheet_preparation_lines', CrewTimesheetPreparationLine::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->withTrashed()->count(), 0, 'Includes soft-deleted rows'),
            $this->row('crew_timesheet_preparations', CrewTimesheetPreparation::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->withTrashed()->count(), 0, 'Includes soft-deleted rows'),
            $this->row('crew_movement_corrections', CrewMovementCorrection::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_scheduled_movements', CrewScheduledMovement::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_accommodation_stays', CrewAccommodationStay::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('employee_trainings (assignment-generated)', EmployeeTraining::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->whereNotNull('source_crew_assignment_phase_id')->count(), $manualTrainingPreserve, 'Manual training preserved'),
            $this->row('employee_sea_services (assignment-synced)', $syncedSeaServiceDelete, $manualSeaServicePreserve, 'Manual sea service preserved'),
            $this->row('historical_crew_import_rows', HistoricalCrewImportRow::query()->when($companyId !== null, fn (Builder $q) => $q->whereIn('historical_crew_import_batch_id', HistoricalCrewImportBatch::query()->where('company_id', $companyId)->select('id')))->count(), 0, ''),
            $this->row('crew_planning_assignments', CrewPlanningAssignment::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->withTrashed()->count(), 0, 'Operational planning rows only'),
            $this->row('crew_assignments', count($scope->assignmentIds), 0, 'Includes voided and soft-deleted rows'),
            $this->row('crew_assignment_phases', count($scope->phaseIds), 0, 'Includes soft-deleted rows'),
            $this->row('historical_crew_import_batches', HistoricalCrewImportBatch::query()->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))->count(), 0, ''),
            $this->row('crew_operational_alerts (+ deliveries)', $this->operationalAlertDeleteCount($companyId), 0, 'Recipients and delivery rows included'),
            $this->row('activity_log (crew assignment & planning)', $activityDelete, 0, 'Assignment-related audit rows'),
            $this->row('payroll_records (office)', 0, $officePayrollPreserve, 'Never deleted by this command'),
            $this->row('employees / vessels / settings', 0, 1, 'Master data always preserved'),
        ];

        $sequenceRows = $this->sequenceRows($scope->companyIds);

        return new CrewOperationsResetPlan(
            companyId: $companyId,
            rows: $rows,
            sequenceRows: $sequenceRows,
            blockingIssues: $scope->blockingIssues,
            warnings: $warnings,
            assignmentIds: $scope->assignmentIds,
            phaseIds: $scope->phaseIds,
            crewTimesheetIds: $scope->crewTimesheetIds,
            eligiblePayrollRecordIds: $scope->eligiblePayrollRecordIds,
            companyIds: $scope->companyIds,
        );
    }

    private function countSegments(?int $companyId): int
    {
        return CrewTimesheetSegment::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->withTrashed()
            ->count();
    }

    private function operationalAlertDeleteCount(?int $companyId): int
    {
        $alertIds = DB::table('crew_operational_alerts')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
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
