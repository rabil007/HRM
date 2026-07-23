<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodCreationSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Support\Payroll\RegularPayrollPeriodKey;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

final class EnsureFuturePayrollPeriods
{
    /**
     * Ensure automatic Crew and Office payroll periods exist for the rolling
     * window starting at the company-local current month.
     */
    public function handle(Company $company, int $months = 3): EnsureFuturePayrollPeriodsResult
    {
        $months = max(1, $months);
        $timezone = CompanyTimezone::forCompany($company);
        $currentMonth = CarbonImmutable::now($timezone)->startOfMonth();

        $createdCount = 0;
        $skippedCount = 0;
        $createdPeriodIds = [];
        $createdSummary = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $monthStart = $currentMonth->addMonths($offset);

            foreach (PayrollCategory::cases() as $category) {
                $period = $this->ensurePeriod($company, $category, $monthStart);

                if ($period === null) {
                    $skippedCount++;

                    continue;
                }

                $createdCount++;
                $createdPeriodIds[] = $period->id;
                $createdSummary[] = [
                    'month' => $monthStart->format('Y-m'),
                    'category' => $category->value,
                ];
            }
        }

        return new EnsureFuturePayrollPeriodsResult(
            createdCount: $createdCount,
            skippedCount: $skippedCount,
            createdPeriodIds: $createdPeriodIds,
            createdSummary: $createdSummary,
        );
    }

    private function ensurePeriod(
        Company $company,
        PayrollCategory $category,
        CarbonImmutable $monthStart,
    ): ?PayrollPeriod {
        $regularKey = RegularPayrollPeriodKey::for((int) $company->id, $category, $monthStart);
        $automaticKey = $this->automaticPeriodKey((int) $company->id, $category, $monthStart);

        if (PayrollPeriod::query()->where('regular_period_key', $regularKey)->exists()) {
            return null;
        }

        if (PayrollPeriod::query()->where('automatic_period_key', $automaticKey)->exists()) {
            return null;
        }

        $mode = $category === PayrollCategory::Crew
            ? CrewTimesheetMode::Hybrid
            : null;

        $name = $category === PayrollCategory::Crew
            ? sprintf('%s - Crew', $monthStart->format('F Y'))
            : sprintf('%s - Office', $monthStart->format('F Y'));

        try {
            return PayrollPeriod::query()->create([
                'company_id' => $company->id,
                'payroll_category' => $category,
                'crew_timesheet_mode' => $mode,
                'name' => $name,
                'start_date' => $monthStart->toDateString(),
                'end_date' => $monthStart->endOfMonth()->toDateString(),
                'status' => PayrollPeriodStatus::Draft,
                'payment_date' => null,
                'generated_at' => null,
                'creation_source' => PayrollPeriodCreationSource::Automatic,
                'automatic_period_key' => $automaticKey,
                'regular_period_key' => $regularKey,
                'notes' => 'Automatically created',
                'created_by' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function automaticPeriodKey(
        int $companyId,
        PayrollCategory $category,
        CarbonImmutable $monthStart,
    ): string {
        return sprintf(
            'company:%d:%s:%s',
            $companyId,
            $category->value,
            $monthStart->format('Y-m'),
        );
    }
}
