<?php

namespace App\Console\Commands;

use App\Models\EmployeeContract;
use Illuminate\Console\Command;

class ExpireContractsCommand extends Command
{
    protected $signature = 'contracts:expire {--company= : Limit to a single company ID}';

    protected $description = 'Mark active contracts whose end_date has passed as ended';

    public function handle(): int
    {
        $query = EmployeeContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString());

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        $count = $query->update(['status' => 'ended']);

        $this->info("Expired {$count} contract(s).");

        return self::SUCCESS;
    }
}
