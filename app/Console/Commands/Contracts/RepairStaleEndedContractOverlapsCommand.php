<?php

namespace App\Console\Commands\Contracts;

use App\Support\Contracts\Actions\RepairStaleEndedContractOverlaps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contracts:repair-stale-ended-overlaps {--company= : Limit repairs to one company ID} {--dry-run : Preview changes without writing}')]
#[Description('Cap ended contract end dates that still overlap a later contract for the same employee')]
class RepairStaleEndedContractOverlapsCommand extends Command
{
    public function handle(RepairStaleEndedContractOverlaps $repair): int
    {
        $companyId = $this->option('company') !== null
            ? (int) $this->option('company')
            : null;
        $dryRun = (bool) $this->option('dry-run');

        $repairs = $repair->handle($companyId, $dryRun);

        if ($repairs === []) {
            $this->info('No stale ended contract overlaps found.');

            return self::SUCCESS;
        }

        foreach ($repairs as $item) {
            $this->line(sprintf(
                'Contract #%d (employee #%d): %s -> %s',
                $item['contract_id'],
                $item['employee_id'],
                $item['previous_end_date'] ?? 'open',
                $item['new_end_date'],
            ));
        }

        $this->info(sprintf(
            '%s %d contract(s).',
            $dryRun ? 'Would repair' : 'Repaired',
            count($repairs),
        ));

        return self::SUCCESS;
    }
}
