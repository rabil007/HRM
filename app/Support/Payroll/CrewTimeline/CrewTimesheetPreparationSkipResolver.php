<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewTimesheetPreparationSkipResolver
{
    public const NON_SKIPPABLE_MESSAGE = 'This issue cannot be skipped because it violates company data isolation.';

    public function __construct(
        private readonly CrewTimelineFreshnessChecker $freshnessChecker,
    ) {}

    /**
     * @return list<int>
     */
    public function activeSkippedEmployeeIds(CrewTimesheetPreparation $preparation): array
    {
        if ($preparation->relationLoaded('skips')) {
            /** @var Collection<int, CrewTimesheetPreparationSkip> $skips */
            $skips = $preparation->skips;

            return $skips
                ->filter(fn (CrewTimesheetPreparationSkip $skip): bool => (int) $skip->company_id === (int) $preparation->company_id
                    && (int) $skip->crew_timesheet_preparation_id === (int) $preparation->id
                    && $skip->isActive()
                )
                ->pluck('employee_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return CrewTimesheetPreparationSkip::query()
            ->where('company_id', (int) $preparation->company_id)
            ->where('crew_timesheet_preparation_id', (int) $preparation->id)
            ->whereNull('restored_at')
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function hasUnresolvedBlockingWarnings(CrewTimesheetPreparation $preparation): bool
    {
        $lines = $this->getPreparationLines($preparation);

        // cross_company_reference can NEVER be bypassed or skipped.
        $hasCrossCompany = $lines->contains(function (CrewTimesheetPreparationLine $line): bool {
            return $line->warning_code === CrewTimelineWarningCode::CrossCompanyReference->value;
        });

        if ($hasCrossCompany) {
            return true;
        }

        $activeSkippedIds = $this->activeSkippedEmployeeIds($preparation);

        return $lines->contains(function (CrewTimesheetPreparationLine $line) use ($activeSkippedIds): bool {
            if ($line->warning_code === null) {
                return false;
            }

            if (in_array((int) $line->employee_id, $activeSkippedIds, true)) {
                return false;
            }

            $code = CrewTimelineWarningCode::tryFrom((string) $line->warning_code);

            return $code !== null && $code->isBlocking();
        });
    }

    public function unresolvedBlockingWarningCount(CrewTimesheetPreparation $preparation): int
    {
        $lines = $this->getPreparationLines($preparation);
        $activeSkippedIds = $this->activeSkippedEmployeeIds($preparation);
        $count = 0;

        foreach ($lines as $line) {
            if ($line->warning_code === null) {
                continue;
            }

            if ($line->warning_code === CrewTimelineWarningCode::CrossCompanyReference->value) {
                $count++;

                continue;
            }

            if (in_array((int) $line->employee_id, $activeSkippedIds, true)) {
                continue;
            }

            $code = CrewTimelineWarningCode::tryFrom((string) $line->warning_code);

            if ($code !== null && $code->isBlocking()) {
                $count++;
            }
        }

        return $count;
    }

    public function assertNoUnresolvedBlockingWarnings(CrewTimesheetPreparation $preparation): void
    {
        if ($this->hasUnresolvedBlockingWarnings($preparation)) {
            throw ValidationException::withMessages([
                'preparation' => 'Blocking warnings must be resolved before continuing. Correct Crew Operations data and prepare a new version.',
            ]);
        }
    }

    public function assertEmployeeCanBeSkipped(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        Employee $employee,
        int $companyId,
    ): void {
        $this->assertTenantOwnership($period, $preparation, $employee, $companyId);
        $this->assertCrewDraftPeriod($period);
        $this->assertPreparationStatusIsDraft($preparation);
        $this->assertLatestVersion($preparation, $companyId);
        $this->freshnessChecker->assertFresh($preparation, $period);

        $lines = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('employee_id', $employee->id)
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'employee' => 'The employee does not have any timeline lines in this preparation.',
            ]);
        }

        // Check if employee or preparation has cross_company_reference
        $preparationHasCrossCompany = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('warning_code', CrewTimelineWarningCode::CrossCompanyReference->value)
            ->exists();

        if ($preparationHasCrossCompany) {
            throw ValidationException::withMessages([
                'employee' => self::NON_SKIPPABLE_MESSAGE,
            ]);
        }

        $hasWarning = $lines->contains(fn (CrewTimesheetPreparationLine $l): bool => $l->warning_code !== null);

        if (! $hasWarning) {
            throw ValidationException::withMessages([
                'employee' => 'Only employees with warnings can be skipped.',
            ]);
        }
    }

    public function assertEmployeeCanBeRestored(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        Employee $employee,
        int $companyId,
    ): void {
        $this->assertTenantOwnership($period, $preparation, $employee, $companyId);
        $this->assertCrewDraftPeriod($period);
        $this->assertPreparationStatusIsDraft($preparation);
        $this->assertLatestVersion($preparation, $companyId);
        $this->freshnessChecker->assertFresh($preparation, $period);
    }

    private function assertTenantOwnership(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        Employee $employee,
        int $companyId,
    ): void {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        if ((int) $preparation->company_id !== $companyId) {
            abort(404);
        }

        if ((int) $preparation->payroll_period_id !== (int) $period->id) {
            abort(404);
        }

        if ((int) $employee->company_id !== $companyId) {
            abort(404);
        }
    }

    private function assertCrewDraftPeriod(PayrollPeriod $period): void
    {
        if (! $period->isCrew()) {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'Crew timeline workflow is only available for crew pay periods.',
            ]);
        }

        if ($period->status !== PayrollPeriodStatus::Draft) {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'Crew timeline workflow is only available for draft pay periods.',
            ]);
        }
    }

    private function assertPreparationStatusIsDraft(CrewTimesheetPreparation $preparation): void
    {
        if ($preparation->status !== CrewTimesheetPreparationStatus::Draft) {
            throw ValidationException::withMessages([
                'preparation' => 'Only draft preparations can have employee timeline data modified.',
            ]);
        }
    }

    private function assertLatestVersion(CrewTimesheetPreparation $preparation, int $companyId): void
    {
        $latestVersion = (int) CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $preparation->payroll_period_id)
            ->max('version');

        if ((int) $preparation->version !== $latestVersion) {
            throw ValidationException::withMessages([
                'preparation' => 'Only the latest preparation version can be modified.',
            ]);
        }
    }

    /**
     * @return Collection<int, CrewTimesheetPreparationLine>
     */
    private function getPreparationLines(CrewTimesheetPreparation $preparation): Collection
    {
        if ($preparation->relationLoaded('lines')) {
            /** @var Collection<int, CrewTimesheetPreparationLine> $lines */
            $lines = $preparation->lines;

            return $lines->filter(fn (CrewTimesheetPreparationLine $line): bool => (int) $line->company_id === (int) $preparation->company_id
                && (int) $line->crew_timesheet_preparation_id === (int) $preparation->id
            )->values();
        }

        return $preparation->lines()
            ->where('company_id', (int) $preparation->company_id)
            ->get();
    }
}
