<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Console\Command;

class SyncLeaveBalancesCommand extends Command
{
    private const ANOMALY_PREVIEW_LIMIT = 20;

    protected $signature = 'leave-balances:sync {year? : Limit sync to a single year}';

    protected $description = 'Rebuild leave balances from leave requests and leave type rules';

    public function handle(LeaveBalanceManager $leaveBalances): int
    {
        $year = $this->argument('year') !== null ? (int) $this->argument('year') : null;
        $synced = 0;
        /** @var list<array{company_id: int, employee_id: int, leave_type_id: int, year: int, message: string}> $anomalies */
        $anomalies = [];

        Company::query()
            ->where('status', 'active')
            ->select('id')
            ->orderBy('id')
            ->chunkById(50, function ($companies) use ($leaveBalances, $year, &$synced, &$anomalies): void {
                foreach ($companies as $company) {
                    $synced += $leaveBalances->syncCompany(
                        (int) $company->id,
                        $year,
                        function (array $anomaly) use (&$anomalies): void {
                            $anomalies[] = $anomaly;
                        },
                    );
                }
            });

        $this->info("Synced {$synced} leave balance row(s).");

        $anomalyCount = count($anomalies);

        if ($anomalyCount > 0) {
            $label = $anomalyCount === 1
                ? 'WARNING: 1 historical leave balance anomaly was skipped because entitlement could not be safely reconstructed.'
                : "WARNING: {$anomalyCount} historical leave balance anomalies were skipped because entitlement could not be safely reconstructed.";

            $this->warn($label);

            foreach (array_slice($anomalies, 0, self::ANOMALY_PREVIEW_LIMIT) as $anomaly) {
                $this->line(sprintf(
                    '- Company %d / Employee %d / Leave Type %d / %d',
                    $anomaly['company_id'],
                    $anomaly['employee_id'],
                    $anomaly['leave_type_id'],
                    $anomaly['year'],
                ));
            }

            $remaining = $anomalyCount - self::ANOMALY_PREVIEW_LIMIT;

            if ($remaining > 0) {
                $this->line("... and {$remaining} more.");
            }
        }

        return self::SUCCESS;
    }
}
