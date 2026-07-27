<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ApplyManualImportTimesheetAutoApproval;
use App\Support\Payroll\ValidateCrewTimesheetOperationalIntegrity;
use Illuminate\Support\Facades\DB;

final class NormalizeLegacyManualImportTimesheetApprovals
{
    public function __construct(
        private readonly ValidateCrewTimesheetOperationalIntegrity $validateIntegrity,
        private readonly ApplyManualImportTimesheetAutoApproval $autoApproval,
    ) {}

    /**
     * @return array{normalized: int, skipped_invalid: int, skipped_ineligible: int}
     */
    public function handle(?Company $company = null): array
    {
        $normalized = 0;
        $skippedInvalid = 0;
        $skippedIneligible = 0;

        $query = CrewTimesheet::query()
            ->whereIn('source', [
                CrewTimesheetSource::Manual->value,
                CrewTimesheetSource::Import->value,
            ])
            ->whereIn('approval_status', [
                CrewTimesheetApprovalStatus::Draft->value,
                CrewTimesheetApprovalStatus::Submitted->value,
                CrewTimesheetApprovalStatus::Returned->value,
            ])
            ->with(['employee', 'period']);

        if ($company !== null) {
            $query->where('company_id', $company->id);
        }

        foreach ($query->cursor() as $timesheet) {
            /** @var CrewTimesheet $timesheet */
            if (! $this->isEligiblePeriod($timesheet->period)) {
                $skippedIneligible++;

                continue;
            }

            $employee = $timesheet->employee;

            if ($employee === null || (int) $employee->company_id !== (int) $timesheet->company_id) {
                $skippedIneligible++;

                continue;
            }

            $integrity = $this->validateIntegrity->handle($timesheet, $employee);

            if ($integrity !== null) {
                $skippedInvalid++;

                continue;
            }

            $previousStatus = $timesheet->approval_status?->value;

            DB::transaction(function () use ($timesheet, $previousStatus, &$normalized): void {
                activity()
                    ->performedOn($timesheet)
                    ->withProperties([
                        'event' => 'crew_timesheet_auto_approved_policy_migration',
                        'previous_approval_status' => $previousStatus,
                        'source' => $timesheet->source?->value,
                        'payroll_period_id' => $timesheet->period_id,
                        'company_id' => $timesheet->company_id,
                    ])
                    ->log('crew_timesheet_auto_approved_policy_migration');

                $timesheet->fill([
                    'approval_status' => CrewTimesheetApprovalStatus::Approved,
                    'approved_by' => null,
                    'approved_at' => now(),
                    'submitted_by' => null,
                    'submitted_at' => null,
                    'returned_by' => null,
                    'returned_at' => null,
                    'return_reason' => null,
                ]);
                $timesheet->save();

                $normalized++;
            });
        }

        return [
            'normalized' => $normalized,
            'skipped_invalid' => $skippedInvalid,
            'skipped_ineligible' => $skippedIneligible,
        ];
    }

    private function isEligiblePeriod(?PayrollPeriod $period): bool
    {
        if ($period === null) {
            return false;
        }

        return $period->status === PayrollPeriodStatus::Draft
            && $period->isCrew()
            && $period->isEditable();
    }
}
