<?php

namespace App\Support\CrewOperations\Reset;

use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
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
use App\Models\SalaryInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

final class CrewOperationsResetExecutor
{
    public function execute(CrewOperationsResetPlan $plan): void
    {
        if ($plan->isBlocked()) {
            throw new RuntimeException('Crew operations reset is blocked: '.implode(' ', $plan->blockingIssues));
        }

        DB::transaction(function () use ($plan): void {
            $companyId = $plan->companyId;
            $assignmentIds = $plan->assignmentIds;
            $phaseIds = $plan->phaseIds;
            $crewTimesheetIds = $plan->crewTimesheetIds;
            $eligiblePayrollRecordIds = $plan->eligiblePayrollRecordIds;

            $this->deleteCrewLinkedAllocations($companyId, $assignmentIds, $crewTimesheetIds);

            if ($eligiblePayrollRecordIds !== []) {
                SalaryInput::query()
                    ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                    ->whereIn('period_id', PayrollRecord::query()->whereIn('id', $eligiblePayrollRecordIds)->select('period_id'))
                    ->whereIn('employee_id', PayrollRecord::query()->whereIn('id', $eligiblePayrollRecordIds)->select('employee_id'))
                    ->delete();

                PayrollRecord::query()
                    ->whereIn('id', $eligiblePayrollRecordIds)
                    ->withTrashed()
                    ->forceDelete();
            }

            CrewTimesheetSegment::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->delete();

            CrewTimesheet::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->withTrashed()
                ->forceDelete();

            CrewTimesheetPreparationSkip::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->delete();

            CrewTimesheetPreparationLine::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->withTrashed()
                ->forceDelete();

            CrewTimesheetPreparation::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->withTrashed()
                ->forceDelete();

            CrewMovementCorrection::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->delete();

            CrewScheduledMovement::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->delete();

            CrewAccommodationStay::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->delete();

            EmployeeTraining::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->whereNotNull('source_crew_assignment_phase_id')
                ->delete();

            EmployeeSeaService::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->whereNotNull('crew_assignment_phase_id')
                ->withTrashed()
                ->forceDelete();

            if ($companyId !== null) {
                HistoricalCrewImportRow::query()
                    ->whereIn('historical_crew_import_batch_id', HistoricalCrewImportBatch::query()->where('company_id', $companyId)->select('id'))
                    ->delete();
            } else {
                HistoricalCrewImportRow::query()->delete();
            }

            $this->clearAssignmentGraphReferences($companyId, $assignmentIds);

            CrewPlanningAssignment::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->withTrashed()
                ->forceDelete();

            CrewAssignment::query()
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->withTrashed()
                ->forceDelete();

            if ($companyId !== null) {
                HistoricalCrewImportBatch::query()->where('company_id', $companyId)->delete();
            } else {
                HistoricalCrewImportBatch::query()->delete();
            }

            $this->deleteOperationalAlerts($companyId);
            $this->deleteAssignmentActivity($companyId, $assignmentIds);
            $this->resetAssignmentSequences($plan->companyIds);
        });
    }

    /**
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $crewTimesheetIds
     */
    private function deleteCrewLinkedAllocations(?int $companyId, array $assignmentIds, array $crewTimesheetIds): void
    {
        if ($assignmentIds === [] && $crewTimesheetIds === []) {
            return;
        }

        PayrollWorkAllocation::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(fn (Builder $inner) => $this->applyCrewLinkageScope($inner, $assignmentIds, $crewTimesheetIds))
            ->delete();
    }

    /**
     * @param  list<int>  $assignmentIds
     */
    private function clearAssignmentGraphReferences(?int $companyId, array $assignmentIds): void
    {
        $assignmentQuery = CrewAssignment::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->withTrashed();

        $assignmentUpdates = [
            'current_phase_id' => null,
            'previous_assignment_id' => null,
            'relieves_crew_assignment_id' => null,
        ];

        if (Schema::hasColumn('crew_assignments', 'crew_planning_assignment_id')) {
            $assignmentUpdates['crew_planning_assignment_id'] = null;
        }

        $assignmentQuery->update($assignmentUpdates);

        CrewPlanningAssignment::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->withTrashed()
            ->update([
                'crew_assignment_id' => null,
                'relieves_crew_assignment_id' => null,
            ]);

        HistoricalCrewImportRow::query()
            ->when($assignmentIds !== [], fn (Builder $q) => $q->whereIn('crew_assignment_id', $assignmentIds))
            ->update(['crew_assignment_id' => null]);
    }

    private function deleteOperationalAlerts(?int $companyId): void
    {
        $alertIds = CrewOperationalAlert::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->pluck('id');

        if ($alertIds->isEmpty()) {
            return;
        }

        DB::table('crew_operational_alert_recipients')->whereIn('crew_operational_alert_id', $alertIds)->delete();
        DB::table('crew_operational_alert_email_deliveries')->whereIn('crew_operational_alert_id', $alertIds)->delete();
        DB::table('crew_operational_alert_push_deliveries')->whereIn('crew_operational_alert_id', $alertIds)->delete();
        CrewOperationalAlert::query()->whereIn('id', $alertIds)->delete();
    }

    /**
     * @param  list<int>  $assignmentIds
     */
    private function deleteAssignmentActivity(?int $companyId, array $assignmentIds): void
    {
        Activity::query()
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->where(function (Builder $sub) use ($assignmentIds, $companyId): void {
                $sub->where(function (Builder $inner) use ($assignmentIds): void {
                    $inner->where('subject_type', CrewAssignment::class);
                    if ($assignmentIds !== []) {
                        $inner->whereIn('subject_id', $assignmentIds);
                    } else {
                        $inner->whereRaw('1 = 0');
                    }
                })->orWhere(function (Builder $inner) use ($companyId): void {
                    $inner->where('subject_type', CrewPlanningAssignment::class);
                    if ($companyId !== null) {
                        $inner->where('company_id', $companyId);
                    }
                });
            })
            ->delete();
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

    /**
     * @param  list<int>  $companyIds
     */
    private function resetAssignmentSequences(array $companyIds): void
    {
        $year = (int) config('crew_operations_reset.sequence_reset_year', 2026);

        foreach ($companyIds as $companyId) {
            if ($companyId <= 0) {
                continue;
            }

            CrewAssignmentSequence::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'year' => $year,
                ],
                [
                    'last_number' => 0,
                ],
            );
        }
    }
}
