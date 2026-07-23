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

final class ReturnCrewTimesheetApproval
{
    public function handle(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        User $actor,
        int $companyId,
        string $reason,
    ): CrewTimesheet {
        return DB::transaction(function () use ($period, $timesheet, $actor, $companyId, $reason): CrewTimesheet {
            if ((int) $period->company_id !== $companyId || (int) $timesheet->company_id !== $companyId) {
                abort(404);
            }

            if ((int) $timesheet->period_id !== (int) $period->id) {
                abort(404);
            }

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
                    'timesheet' => 'Timesheets can only be returned while the pay period is draft.',
                ]);
            }

            if ($timesheet->source === CrewTimesheetSource::CrewOperations) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Crew Operations timesheets cannot be returned from this workflow.',
                ]);
            }

            if (! ($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)->canApproveOrReturn()) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Only submitted timesheets can be returned.',
                ]);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'return_reason' => 'A return reason is required.',
                ]);
            }

            $previous = $timesheet->approval_status?->value;

            $timesheet->fill([
                'approval_status' => CrewTimesheetApprovalStatus::Returned,
                'returned_by' => $actor->id,
                'returned_at' => now(),
                'return_reason' => $reason,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $timesheet->save();

            activity()
                ->performedOn($timesheet)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timesheet_returned',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'employee_id' => $timesheet->employee_id,
                    'previous_status' => $previous,
                    'new_status' => CrewTimesheetApprovalStatus::Returned->value,
                    'return_reason' => $reason,
                ])
                ->log('Crew timesheet returned');

            return $timesheet->fresh() ?? $timesheet;
        });
    }
}
