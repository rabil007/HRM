<?php

namespace App\Support\Payroll\CrewTimeline\Actions;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationWorkflowGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReturnCrewTimesheetPreparation
{
    public function __construct(
        private readonly CrewTimesheetPreparationWorkflowGuard $guard,
    ) {}

    public function handle(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        User $actor,
        int $companyId,
        string $decisionNotes,
    ): CrewTimesheetPreparation {
        $notes = trim($decisionNotes);

        if ($notes === '') {
            throw ValidationException::withMessages([
                'decision_notes' => 'Return notes are required.',
            ]);
        }

        return DB::transaction(function () use ($period, $preparation, $actor, $companyId, $notes): CrewTimesheetPreparation {
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
                'Only submitted preparations can be returned.',
            );

            $previousStatus = $preparation->status->value;

            $preparation->fill([
                'status' => CrewTimesheetPreparationStatus::Returned,
                'returned_by' => $actor->id,
                'returned_at' => now(),
                'decision_notes' => $notes,
            ]);
            $preparation->save();

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timeline_returned',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'version' => $preparation->version,
                    'previous_status' => $previousStatus,
                    'new_status' => CrewTimesheetPreparationStatus::Returned->value,
                    'decision_notes' => $notes,
                ])
                ->log('Crew timesheet preparation returned');

            return $preparation->fresh() ?? $preparation;
        });
    }
}
