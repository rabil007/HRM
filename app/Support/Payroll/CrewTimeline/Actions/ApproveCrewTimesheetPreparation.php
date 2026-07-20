<?php

namespace App\Support\Payroll\CrewTimeline\Actions;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationWorkflowGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveCrewTimesheetPreparation
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
        ?string $decisionNotes = null,
    ): CrewTimesheetPreparation {
        return DB::transaction(function () use ($period, $preparation, $actor, $companyId, $decisionNotes): CrewTimesheetPreparation {
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
                CrewTimesheetPreparationStatus::Submitted,
                'Only submitted preparations can be approved.',
            );
            $this->freshnessChecker->assertFresh($preparation, $period);
            $this->guard->assertNoBlockingWarnings($preparation);

            if (
                (int) $preparation->prepared_by === (int) $actor->id
                || (int) $preparation->submitted_by === (int) $actor->id
            ) {
                throw ValidationException::withMessages([
                    'preparation' => 'You cannot approve a preparation that you prepared or submitted.',
                ]);
            }

            $appliedExists = CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->where('status', CrewTimesheetPreparationStatus::Applied)
                ->exists();

            if ($appliedExists) {
                throw ValidationException::withMessages([
                    'preparation' => 'An applied preparation already exists for this pay period. Replacement must use a payroll correction workflow.',
                ]);
            }

            $notes = $decisionNotes !== null && trim($decisionNotes) !== ''
                ? trim($decisionNotes)
                : null;

            $supersededIds = [];

            $previousApproved = CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->where('status', CrewTimesheetPreparationStatus::Approved)
                ->whereKeyNot($preparation->id)
                ->get();

            foreach ($previousApproved as $previous) {
                $supersededIds[] = $previous->id;
                $previous->fill([
                    'status' => CrewTimesheetPreparationStatus::Superseded,
                ]);
                $previous->save();
            }

            $previousStatus = $preparation->status->value;

            $preparation->fill([
                'status' => CrewTimesheetPreparationStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_notes' => $notes,
            ]);
            $preparation->save();

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timeline_approved',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'version' => $preparation->version,
                    'previous_status' => $previousStatus,
                    'new_status' => CrewTimesheetPreparationStatus::Approved->value,
                    'decision_notes' => $notes,
                    'superseded_preparation_ids' => $supersededIds,
                ])
                ->log('Crew timesheet preparation approved');

            return $preparation->fresh() ?? $preparation;
        });
    }
}
