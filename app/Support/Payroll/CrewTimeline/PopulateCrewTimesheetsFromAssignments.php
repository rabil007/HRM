<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * One-step Crew Timesheet population for the simplified payroll workspace.
 *
 * Creates a preparation snapshot from eligible Crew Assignment phases and
 * immediately writes payable operational days onto CrewTimesheet rows. The
 * dormant multi-step submit/approve/return/apply workflow is not used.
 */
final class PopulateCrewTimesheetsFromAssignments
{
    public function __construct(
        private readonly PrepareCrewTimesheetTimeline $prepare,
        private readonly ApplyCrewTimesheetPreparation $apply,
    ) {}

    /**
     * @return array{
     *     preparation: CrewTimesheetPreparation,
     *     applied_employee_count: int,
     *     created_timesheet_count: int,
     *     updated_timesheet_count: int,
     *     skipped_employee_count: int,
     *     was_refresh: bool
     * }
     */
    public function handle(
        PayrollPeriod $period,
        User $actor,
        int $companyId,
        ?CarbonInterface $cutoffDate = null,
    ): array {
        return DB::transaction(function () use ($period, $actor, $companyId, $cutoffDate): array {
            $wasRefresh = CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->where('status', CrewTimesheetPreparationStatus::Applied)
                ->exists();

            $this->supersedeAppliedPreparations($period, $companyId, $actor);

            $preparation = $this->prepare->handle(
                $period,
                $companyId,
                (int) $actor->id,
                $cutoffDate,
            );

            $result = $this->apply->handleDirectFromDraft(
                $period,
                $preparation,
                $actor,
                $companyId,
            );

            return [
                'preparation' => $preparation->fresh() ?? $preparation,
                'applied_employee_count' => $result->appliedEmployeeCount,
                'created_timesheet_count' => $result->createdTimesheetCount,
                'updated_timesheet_count' => $result->updatedTimesheetCount,
                'skipped_employee_count' => $result->skippedEmployeeCount,
                'was_refresh' => $wasRefresh,
            ];
        });
    }

    private function supersedeAppliedPreparations(
        PayrollPeriod $period,
        int $companyId,
        User $actor,
    ): void {
        $applied = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->lockForUpdate()
            ->get();

        foreach ($applied as $preparation) {
            $previousStatus = $preparation->status->value;

            $preparation->fill([
                'status' => CrewTimesheetPreparationStatus::Superseded,
            ]);
            $preparation->save();

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timeline_superseded',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'version' => $preparation->version,
                    'previous_status' => $previousStatus,
                    'new_status' => CrewTimesheetPreparationStatus::Superseded->value,
                ])
                ->log('Crew timesheet preparation superseded for refresh from Crew Assignments');
        }
    }
}
