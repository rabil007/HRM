<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Console\Command;

class RolloverLeaveBalancesCommand extends Command
{
    protected $signature = 'leave-balances:rollover {year? : The year to open balances for}';

    protected $description = 'Create leave balances for a new year and apply carry-forward rules';

    public function handle(LeaveBalanceManager $leaveBalances): int
    {
        $year = $this->argument('year') !== null
            ? (int) $this->argument('year')
            : (int) now()->year;

        $created = 0;

        Company::query()
            ->where('status', 'active')
            ->select('id')
            ->orderBy('id')
            ->chunkById(50, function ($companies) use ($leaveBalances, $year, &$created): void {
                foreach ($companies as $company) {
                    $created += $leaveBalances->rolloverCompany((int) $company->id, $year);
                }
            });

        $this->info("Opened {$created} leave balance row(s) for {$year}.");

        return self::SUCCESS;
    }
}
