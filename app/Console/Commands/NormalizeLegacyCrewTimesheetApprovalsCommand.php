<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Payroll\Actions\NormalizeLegacyManualImportTimesheetApprovals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payroll:normalize-legacy-crew-timesheet-approvals {--company= : Restrict to a single company id}')]
#[Description('Normalize valid legacy Manual/Import crew timesheets on draft periods to Approved status')]
class NormalizeLegacyCrewTimesheetApprovalsCommand extends Command
{
    public function handle(NormalizeLegacyManualImportTimesheetApprovals $action): int
    {
        $companyOption = $this->option('company');
        $company = null;

        if ($companyOption !== null && $companyOption !== '') {
            $company = Company::query()->find((int) $companyOption);

            if ($company === null) {
                $this->error("Company [{$companyOption}] was not found.");

                return self::FAILURE;
            }
        }

        $result = $action->handle($company);

        $this->info(sprintf(
            'Normalized %d timesheet(s). Skipped %d invalid and %d ineligible row(s).',
            $result['normalized'],
            $result['skipped_invalid'],
            $result['skipped_ineligible'],
        ));

        return self::SUCCESS;
    }
}
