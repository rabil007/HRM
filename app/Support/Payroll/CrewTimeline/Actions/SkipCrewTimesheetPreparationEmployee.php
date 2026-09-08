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

final class SkipCrewTimesheetPreparationEmployee
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
        string $reason,
    ): CrewTimesheetPreparationSkip {
        return DB::transaction(function () use ($period, $preparation, $employee, $actor, $companyId, $reason): CrewTimesheetPreparationSkip {
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

            $this->skipResolver->assertEmployeeCanBeSkipped($period, $preparation, $employee, $companyId);

            $warningCodes = CrewTimesheetPreparationLine::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('employee_id', $employee->id)
                ->whereNotNull('warning_code')
                ->pluck('warning_code')
                ->unique()
                ->values()
                ->all();

            $cleanReason = trim($reason);

            $skip = CrewTimesheetPreparationSkip::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            if ($skip === null) {
                $skip = CrewTimesheetPreparationSkip::query()->create([
                    'company_id' => $companyId,
                    'crew_timesheet_preparation_id' => $preparation->id,
                    'employee_id' => $employee->id,
                    'reason' => $cleanReason,
                    'skipped_by' => $actor->id,
                    'skipped_at' => now(),
                    'restored_by' => null,
                    'restored_at' => null,
                ]);
            } else {
                $skip->fill([
                    'reason' => $cleanReason,
                    'skipped_by' => $actor->id,
                    'skipped_at' => now(),
                    'restored_by' => null,
                    'restored_at' => null,
                ]);
                $skip->save();
            }

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->event('crew_timeline_employee_skipped')
                ->withProperties([
                    'event' => 'crew_timeline_employee_skipped',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'preparation_version' => $preparation->version,
                    'employee_id' => $employee->id,
                    'reason' => $cleanReason,
                    'actor_id' => $actor->id,
                    'timestamp' => now()->toIso8601String(),
                    'warning_codes' => $warningCodes,
                ])
                ->log("Skipped timeline data for employee {$employee->name} in preparation v{$preparation->version}");

            return $skip->fresh() ?? $skip;
        });
    }
}
