<?php

namespace App\Support\Payroll\CrewTimeline\Actions;

use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationSkipResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestoreCrewTimesheetPreparationEmployee
{
    public function __construct(
        private readonly CrewTimesheetPreparationSkipResolver $skipResolver,
    ) {}

    public function handle(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        Employee $employee,
        User $actor,
        int $companyId,
    ): CrewTimesheetPreparationSkip {
        return DB::transaction(function () use ($period, $preparation, $employee, $actor, $companyId): CrewTimesheetPreparationSkip {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $preparation = CrewTimesheetPreparation::query()
                ->whereKey($preparation->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $employee = Employee::query()
                ->whereKey($employee->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->skipResolver->assertEmployeeCanBeRestored($period, $preparation, $employee, $companyId);

            $skip = CrewTimesheetPreparationSkip::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            if ($skip === null) {
                throw ValidationException::withMessages([
                    'employee' => 'No skip record exists for this employee.',
                ]);
            }

            // Double restore is handled safely / idempotently
            if ($skip->restored_at !== null) {
                return $skip;
            }

            $originalSkipReason = $skip->reason;

            $warningCodes = CrewTimesheetPreparationLine::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('employee_id', $employee->id)
                ->whereNotNull('warning_code')
                ->pluck('warning_code')
                ->unique()
                ->values()
                ->all();

            $skip->fill([
                'restored_by' => $actor->id,
                'restored_at' => now(),
            ]);
            $skip->save();

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->event('crew_timeline_employee_skip_restored')
                ->withProperties([
                    'event' => 'crew_timeline_employee_skip_restored',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'preparation_version' => $preparation->version,
                    'employee_id' => $employee->id,
                    'actor_id' => $actor->id,
                    'original_skip_reason' => $originalSkipReason,
                    'warning_codes' => $warningCodes,
                    'timestamp' => now()->toIso8601String(),
                ])
                ->log("Restored timeline data for employee {$employee->name} in preparation v{$preparation->version}");

            return $skip->fresh() ?? $skip;
        });
    }
}
