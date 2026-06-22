<?php

namespace App\Console\Commands;

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\ContractSalaryComponentCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payroll:sync-contract-salary-components {--dry-run : Preview counts without saving} {--company= : Limit to one company ID}')]
#[Description('One-time backfill: sync contract_salary_components from existing employee_contracts')]
class SyncAllContractSalaryComponentsCommand extends Command
{
    public function handle(): int
    {
        if (! $this->tableExists()) {
            $this->error('Table contract_salary_components does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company') !== null
            ? (int) $this->option('company')
            : null;

        $query = EmployeeContract::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id');

        $totalContracts = (clone $query)->count();

        if ($totalContracts === 0) {
            $this->warn('No employee contracts found.');

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'Dry run — no changes will be saved.' : 'Syncing salary components...');
        $this->line("Contracts to process: {$totalContracts}");

        $synced = 0;
        $activeComponents = 0;

        $query->chunkById(100, function ($contracts) use ($dryRun, &$synced, &$activeComponents): void {
            foreach ($contracts as $contract) {
                if (! $dryRun) {
                    (new SyncContractSalaryComponentsFromContract)->handle($contract);
                }

                $synced++;

                if ($dryRun) {
                    $category = $contract->payroll_category ?? PayrollCategory::Office;
                    $activeComponents += $this->estimatedActiveComponents($contract, $category);
                }
            }
        });

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run complete.');
            $this->info("Contracts: {$synced}");
            $this->info("Estimated active components: {$activeComponents}");

            return self::SUCCESS;
        }

        $activeComponents = ContractSalaryComponent::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', SalaryComponentStatus::Active->value)
            ->count();

        $this->newLine();
        $this->info('Sync complete.');
        $this->info("Contracts synced: {$synced}");
        $this->info("Active salary components: {$activeComponents}");

        return self::SUCCESS;
    }

    private function tableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('contract_salary_components');
    }

    private function estimatedActiveComponents(EmployeeContract $contract, PayrollCategory $category): int
    {
        $count = 0;

        foreach (ContractSalaryComponentCatalog::legacyColumnMap($category) as $column => $code) {
            $amount = $contract->{$column};

            if ($amount !== null && $amount !== '' && (float) $amount > 0) {
                $count++;
            }
        }

        return $count;
    }
}
