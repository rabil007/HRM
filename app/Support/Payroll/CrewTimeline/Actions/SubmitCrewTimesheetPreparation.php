<?php

namespace App\Support\Payroll\CrewTimeline\Actions;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationWorkflowGuard;
use Illuminate\Support\Facades\DB;

final class SubmitCrewTimesheetPreparation
{
    public function __construct(
        private readonly CrewTimesheetPreparationWorkflowGuard $guard,
        private readonly CrewTimelineFreshnessChecker $freshnessChecker,
    ) {}

    public function handle(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        User $actor,
        int $companyId,
    ): CrewTimesheetPreparation {
        return DB::transaction(function () use ($period, $preparation, $actor, $companyId): CrewTimesheetPreparation {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->lockForUpdate()
                ->get();

            $preparation = CrewTimesheetPreparation::query()
                ->whereKey($preparation->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard->assertTenantOwnership($period, $preparation, $companyId);
            $this->guard->assertCrewDraftPeriod($period);
            $this->guard->assertStatus(
                $preparation,
                CrewTimesheetPreparationStatus::Draft,
                'Only draft preparations can be submitted.',
            );
            $this->guard->assertLatestVersion($preparation, $companyId);
            $this->guard->assertNoOtherSubmitted($preparation, $companyId);
            $this->freshnessChecker->assertFresh($preparation, $period);
            $this->guard->assertNoBlockingWarnings($preparation);

            $previousStatus = $preparation->status->value;

            $preparation->fill([
                'status' => CrewTimesheetPreparationStatus::Submitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);
            $preparation->save();

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timeline_submitted',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'version' => $preparation->version,
                    'previous_status' => $previousStatus,
                    'new_status' => CrewTimesheetPreparationStatus::Submitted->value,
                ])
                ->log('Crew timesheet preparation submitted');

            return $preparation->fresh() ?? $preparation;
        });
    }
}
