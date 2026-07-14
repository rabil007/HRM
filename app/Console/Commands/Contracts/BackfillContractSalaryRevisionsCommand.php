<?php

namespace App\Console\Commands\Contracts;

use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contracts:backfill-salary-revisions')]
#[Description('Create initial salary revisions for contracts that do not have any')]
class BackfillContractSalaryRevisionsCommand extends Command
{
    public function __construct(
        private readonly ApplyContractSalaryRevision $applySalaryRevision,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;

        EmployeeContract::query()
            ->whereDoesntHave('salaryRevisions')
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use (&$created, &$skipped): void {
                foreach ($contracts as $contract) {
                    $amounts = ApplyContractSalaryRevision::amountsFromContract($contract);
                    $hasPositiveAmount = collect($amounts)->contains(
                        fn (mixed $amount): bool => $amount !== null && $amount !== '' && (float) $amount > 0,
                    );

                    if (! $hasPositiveAmount) {
                        $skipped++;

                        continue;
                    }

                    $this->applySalaryRevision->handle(
                        $contract,
                        $amounts,
                        $contract->start_date?->toDateString() ?? $contract->created_at?->toDateString() ?? now()->toDateString(),
                        'Backfilled initial contract salary',
                        null,
                    );

                    $created++;
                }
            });

        $this->info("Created {$created} salary revision(s). Skipped {$skipped} contract(s) without positive salary amounts.");

        return self::SUCCESS;
    }
}
