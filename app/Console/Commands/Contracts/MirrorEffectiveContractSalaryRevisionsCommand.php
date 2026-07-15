<?php

namespace App\Console\Commands\Contracts;

use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\MirrorLatestContractSalaryRevision;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contracts:mirror-effective-salary-revisions {--company= : Limit to a single company ID}')]
#[Description('Mirror salary revisions that became effective this month onto employee contracts')]
class MirrorEffectiveContractSalaryRevisionsCommand extends Command
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $mirrored = 0;

        $query = EmployeeContract::query()
            ->where('status', 'active')
            ->whereHas('salaryRevisions', function ($revisionQuery) use ($monthStart, $today): void {
                $revisionQuery
                    ->whereDate('effective_from', '>=', $monthStart)
                    ->whereDate('effective_from', '<=', $today);
            });

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        $query
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use (&$mirrored): void {
                foreach ($contracts as $contract) {
                    $this->mirrorLatestRevision->handle($contract);
                    $mirrored++;
                }
            });

        $this->info("Mirrored {$mirrored} contract(s).");

        return self::SUCCESS;
    }
}
