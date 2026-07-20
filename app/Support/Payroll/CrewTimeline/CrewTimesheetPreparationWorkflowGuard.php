<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use Illuminate\Validation\ValidationException;

final class CrewTimesheetPreparationWorkflowGuard
{
    public function assertTenantOwnership(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
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
    }

    public function assertCrewDraftPeriod(PayrollPeriod $period): void
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

    public function assertLatestVersion(
        CrewTimesheetPreparation $preparation,
        int $companyId,
    ): void {
        $latestVersion = (int) CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $preparation->payroll_period_id)
            ->max('version');

        if ((int) $preparation->version !== $latestVersion) {
            throw ValidationException::withMessages([
                'preparation' => 'Only the latest preparation version can be submitted.',
            ]);
        }
    }

    public function assertNoBlockingWarnings(CrewTimesheetPreparation $preparation): void
    {
        $hasBlocking = $preparation->lines()
            ->whereNotNull('warning_code')
            ->get(['warning_code'])
            ->contains(function ($line): bool {
                $code = CrewTimelineWarningCode::tryFrom((string) $line->warning_code);

                return $code !== null && $code->isBlocking();
            });

        if ($hasBlocking) {
            throw ValidationException::withMessages([
                'preparation' => 'Blocking warnings must be resolved before continuing. Correct Crew Operations data and prepare a new version.',
            ]);
        }
    }

    public function assertNoOtherSubmitted(
        CrewTimesheetPreparation $preparation,
        int $companyId,
    ): void {
        $exists = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $preparation->payroll_period_id)
            ->where('status', CrewTimesheetPreparationStatus::Submitted)
            ->whereKeyNot($preparation->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'preparation' => 'Another preparation is already submitted for this pay period.',
            ]);
        }
    }

    public function assertStatus(
        CrewTimesheetPreparation $preparation,
        CrewTimesheetPreparationStatus $expected,
        string $message,
    ): void {
        if ($preparation->status !== $expected) {
            throw ValidationException::withMessages([
                'preparation' => $message,
            ]);
        }
    }
}
