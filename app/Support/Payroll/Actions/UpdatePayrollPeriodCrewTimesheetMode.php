<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdatePayrollPeriodCrewTimesheetMode
{
    public function handle(
        PayrollPeriod $period,
        int $companyId,
        CrewTimesheetMode $mode,
    ): PayrollPeriod {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($period, $companyId, $mode): PayrollPeriod {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanChangeMode($period, $companyId);

            if (! $period->isCrew()) {
                throw ValidationException::withMessages([
                    'crew_timesheet_mode' => 'Timesheet source applies only to crew pay periods.',
                ]);
            }

            $period->fill([
                'crew_timesheet_mode' => $mode,
            ]);
            $period->save();

            return $period->fresh() ?? $period;
        });
    }

    public function assertCanChangeMode(PayrollPeriod $period, int $companyId): void
    {
        if ($period->status !== PayrollPeriodStatus::Draft) {
            throw ValidationException::withMessages([
                'crew_timesheet_mode' => 'Timesheet source can only be changed while the pay period is draft.',
            ]);
        }

        if (PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'crew_timesheet_mode' => 'Timesheet source cannot be changed after payroll records exist.',
            ]);
        }

        if (CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'crew_timesheet_mode' => 'Timesheet source cannot be changed after crew timesheets exist.',
            ]);
        }

        if (CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'crew_timesheet_mode' => 'Timesheet source cannot be changed after a Crew Operations timeline preparation exists.',
            ]);
        }
    }

    public function resolveModeForCreate(
        PayrollCategory $category,
        ?string $modeValue,
    ): ?CrewTimesheetMode {
        if ($category !== PayrollCategory::Crew) {
            return null;
        }

        if ($modeValue === null || $modeValue === '') {
            return CrewTimesheetMode::Manual;
        }

        return CrewTimesheetMode::from($modeValue);
    }
}
