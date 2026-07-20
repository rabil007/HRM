<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewOperationsPayrollGenerationGuard
{
    public const MISSING_APPLIED_MESSAGE = 'Apply the approved Crew Operations timeline before generating payroll.';

    /**
     * @param  Collection<int, Employee>  $employees
     */
    public function assertReadyForGeneration(
        PayrollPeriod $period,
        Collection $employees,
        int $companyId,
    ): CrewTimesheetPreparation {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        if ($period->crew_timesheet_mode !== CrewTimesheetMode::CrewOperations) {
            throw ValidationException::withMessages([
                'period_id' => 'Crew Operations generation safeguards apply only to Crew Operations Timeline periods.',
            ]);
        }

        $applied = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->lockForUpdate()
            ->get();

        if ($applied->count() === 0) {
            throw ValidationException::withMessages([
                'period_id' => self::MISSING_APPLIED_MESSAGE,
            ]);
        }

        if ($applied->count() > 1) {
            throw ValidationException::withMessages([
                'period_id' => 'Multiple Applied Crew Operations timelines were found for this pay period.',
            ]);
        }

        /** @var CrewTimesheetPreparation $preparation */
        $preparation = $applied->first();

        $hasBlocking = $preparation->lines()
            ->whereNotNull('warning_code')
            ->get(['warning_code'])
            ->contains(function ($line): bool {
                $code = CrewTimelineWarningCode::tryFrom((string) $line->warning_code);

                return $code !== null && $code->isBlocking();
            });

        if ($hasBlocking) {
            throw ValidationException::withMessages([
                'period_id' => 'The Applied Crew Operations timeline still has blocking warnings and cannot be used for payroll generation.',
            ]);
        }

        $payableEmployeeIds = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->whereNull('warning_code')
            ->where('days', '>', 0)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $payableEmployeeIds = array_values(array_intersect(
            $payableEmployeeIds,
            $employees->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ));

        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $structure = $employee->currentContract?->resolvedSalaryStructure()
                ?? ContractSalaryStructure::Daily;

            if ($structure === ContractSalaryStructure::Monthly) {
                continue;
            }

            if (! in_array((int) $employee->id, $payableEmployeeIds, true)) {
                continue;
            }

            $this->assertDailyTimesheetLinked($employee, $period, $preparation, $companyId);
        }

        return $preparation;
    }

    /**
     * @return array{
     *     ready: bool,
     *     blocking_reason: string|null,
     *     applied_preparation_id: int|null,
     *     applied_preparation_version: int|null
     * }
     */
    public function readiness(PayrollPeriod $period, int $companyId): array
    {
        if (! $period->isCrew() || $period->crew_timesheet_mode !== CrewTimesheetMode::CrewOperations) {
            return [
                'ready' => true,
                'blocking_reason' => null,
                'applied_preparation_id' => null,
                'applied_preparation_version' => null,
            ];
        }

        $applied = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->first();

        if ($applied === null) {
            return [
                'ready' => false,
                'blocking_reason' => self::MISSING_APPLIED_MESSAGE,
                'applied_preparation_id' => null,
                'applied_preparation_version' => null,
            ];
        }

        return [
            'ready' => true,
            'blocking_reason' => null,
            'applied_preparation_id' => $applied->id,
            'applied_preparation_version' => $applied->version,
        ];
    }

    private function assertDailyTimesheetLinked(
        Employee $employee,
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        int $companyId,
    ): void {
        $timesheet = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($timesheet === null) {
            throw ValidationException::withMessages([
                'period_id' => "Daily crew employee {$employee->name} is missing a Crew Operations timesheet linked to the Applied timeline.",
            ]);
        }

        if ($timesheet->source !== CrewTimesheetSource::CrewOperations) {
            throw ValidationException::withMessages([
                'period_id' => "Daily crew employee {$employee->name} timesheet source must be Crew Operations.",
            ]);
        }

        if ((int) $timesheet->crew_timesheet_preparation_id !== (int) $preparation->id) {
            throw ValidationException::withMessages([
                'period_id' => "Daily crew employee {$employee->name} timesheet is not linked to the Applied timeline.",
            ]);
        }

        if ($timesheet->movement_source_hash !== $preparation->source_hash) {
            throw ValidationException::withMessages([
                'period_id' => "Daily crew employee {$employee->name} timesheet movement source hash does not match the Applied timeline.",
            ]);
        }

        if ($timesheet->operational_approved_by === null || $timesheet->operational_approved_at === null) {
            throw ValidationException::withMessages([
                'period_id' => "Daily crew employee {$employee->name} timesheet is missing operational approval metadata.",
            ]);
        }
    }
}
