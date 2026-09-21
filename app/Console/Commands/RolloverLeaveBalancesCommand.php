<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Console\Command;

class RolloverLeaveBalancesCommand extends Command
{
    protected $signature = 'leave-balances:rollover {year? : The year to open balances for}';

    protected $description = 'Create leave balances for a new year and apply carry-forward rules';

    public function handle(LeaveBalanceManager $leaveBalances): int
    {
        $explicitYear = $this->argument('year') !== null
            ? (int) $this->argument('year')
            : null;

        $applied = 0;

        Company::query()
            ->where('status', 'active')
            ->select(['id', 'timezone'])
            ->orderBy('id')
            ->chunkById(50, function ($companies) use ($leaveBalances, $explicitYear, &$applied): void {
                foreach ($companies as $company) {
                    $year = $explicitYear ?? (int) now(CompanyTimezone::forCompany($company))->year;
                    $applied += $leaveBalances->rolloverCompany((int) $company->id, $year);
                }
            });

        $label = $explicitYear !== null ? (string) $explicitYear : 'each company business year';
        $this->info("Applied rollover to {$applied} leave balance row(s) for {$label}.");

        return self::SUCCESS;
    }
}
