<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitCrewTimesheetApproval
{
    public function handle(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        User $actor,
        int $companyId,
    ): CrewTimesheet {
        return DB::transaction(function () use ($period, $timesheet, $actor, $companyId): CrewTimesheet {
            $this->assertOwnership($period, $timesheet, $companyId);

            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $timesheet = CrewTimesheet::query()
                ->whereKey($timesheet->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($period->status !== PayrollPeriodStatus::Draft) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Timesheets can only be submitted while the pay period is draft.',
                ]);
            }

            if ($timesheet->source === CrewTimesheetSource::CrewOperations) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Crew Operations timesheets are approved through the Applied timeline.',
                ]);
            }

            if (! ($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)->canSubmit()) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Only draft or returned timesheets can be submitted.',
                ]);
            }

            $previous = $timesheet->approval_status?->value;

            $timesheet->fill([
                'approval_status' => CrewTimesheetApprovalStatus::Submitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'returned_by' => null,
                'returned_at' => null,
                'return_reason' => null,
            ]);
            $timesheet->save();

            activity()
                ->performedOn($timesheet)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timesheet_submitted',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'employee_id' => $timesheet->employee_id,
                    'previous_status' => $previous,
                    'new_status' => CrewTimesheetApprovalStatus::Submitted->value,
                ])
                ->log('Crew timesheet submitted for approval');

            return $timesheet->fresh() ?? $timesheet;
        });
    }

    private function assertOwnership(PayrollPeriod $period, CrewTimesheet $timesheet, int $companyId): void
    {
        if ((int) $period->company_id !== $companyId || (int) $timesheet->company_id !== $companyId) {
            abort(404);
        }

        if ((int) $timesheet->period_id !== (int) $period->id) {
            abort(404);
        }
    }
}
