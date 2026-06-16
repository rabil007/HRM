<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Console\Command;

class SyncLeaveBalancesCommand extends Command
{
    protected $signature = 'leave-balances:sync {year? : Limit sync to a single year}';

    protected $description = 'Rebuild leave balances from leave requests and leave type rules';

    public function handle(LeaveBalanceManager $leaveBalances): int
    {
        $year = $this->argument('year') !== null ? (int) $this->argument('year') : null;
        $synced = 0;

        Company::query()
            ->where('status', 'active')
            ->select('id')
            ->orderBy('id')
            ->chunkById(50, function ($companies) use ($leaveBalances, $year, &$synced): void {
                foreach ($companies as $company) {
                    $synced += $leaveBalances->syncCompany((int) $company->id, $year);
                }
            });

        $this->info("Synced {$synced} leave balance row(s).");

        return self::SUCCESS;
    }
}
