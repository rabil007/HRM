<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Payroll\Actions\EnsureFuturePayrollPeriods as EnsureFuturePayrollPeriodsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('payroll:ensure-future-periods {--company= : Restrict to a single company id} {--months=3 : Rolling window size including the current month}')]
#[Description('Ensure automatic Draft payroll periods exist for the rolling window of every active company')]
class EnsureFuturePayrollPeriods extends Command
{
    public function handle(EnsureFuturePayrollPeriodsAction $action): int
    {
        $months = max(1, (int) $this->option('months'));
        $companyOption = $this->option('company');

        if ($companyOption !== null && $companyOption !== '') {
            $company = Company::query()->find((int) $companyOption);

            if ($company === null) {
                $this->error("Company [{$companyOption}] was not found.");

                return self::FAILURE;
            }

            $companies = collect([$company]);
        } else {
            $companies = Company::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->get();
        }

        $totalCreated = 0;
        $totalSkipped = 0;
        $failedCompanies = 0;

        foreach ($companies as $company) {
            try {
                $result = $action->handle($company, $months);
                $totalCreated += $result->createdCount;
                $totalSkipped += $result->skippedCount;

                $this->line(sprintf(
                    'Company #%d "%s": created %d, skipped %d.',
                    $company->id,
                    $company->name,
                    $result->createdCount,
                    $result->skippedCount,
                ));
            } catch (Throwable $exception) {
                $failedCompanies++;

                Log::error('Failed to ensure future payroll periods for company.', [
                    'company_id' => $company->id,
                    'exception' => $exception->getMessage(),
                ]);

                $this->error(sprintf(
                    'Company #%d "%s" failed: %s',
                    $company->id,
                    $company->name,
                    $exception->getMessage(),
                ));
            }
        }

        $this->info(sprintf(
            'Processed %d companies. Created %d periods, skipped %d. Failures: %d.',
            $companies->count(),
            $totalCreated,
            $totalSkipped,
            $failedCompanies,
        ));

        return self::SUCCESS;
    }
}
