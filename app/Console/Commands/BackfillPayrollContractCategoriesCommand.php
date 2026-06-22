<?php

namespace App\Console\Commands;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeContract;
use App\Support\Employees\DepartmentDescendantIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payroll:backfill-contract-categories {--dry-run : Preview counts without saving} {--company= : Limit to one company ID}')]
#[Description('One-time backfill: set contract payroll_category from department root (Marine/Offshore = crew, else office)')]
class BackfillPayrollContractCategoriesCommand extends Command
{
    /**
     * @var list<string>
     */
    private const CREW_ROOT_NAMES = ['marine', 'offshore'];

    public function handle(): int
    {
        if (! $this->payrollCategoryColumnExists()) {
            $this->error('Column employee_contracts.payroll_category does not exist. Run migrations first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company') !== null
            ? (int) $this->option('company')
            : null;

        $companies = Company::query()
            ->when($companyId !== null, fn ($q) => $q->whereKey($companyId))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($companies->isEmpty()) {
            $this->warn('No companies found.');

            return self::SUCCESS;
        }

        $totalCrew = 0;
        $totalOffice = 0;

        foreach ($companies as $company) {
            [$crewDepartmentIds, $crewRoots] = $this->crewDepartmentIdsForCompany((int) $company->id);

            $crewQuery = EmployeeContract::query()
                ->where('company_id', $company->id)
                ->whereHas('employee', fn ($q) => $q->whereIn('department_id', $crewDepartmentIds));

            $officeQuery = EmployeeContract::query()
                ->where('company_id', $company->id)
                ->where(function ($q) use ($crewDepartmentIds) {
                    $q->whereHas('employee', fn ($eq) => $eq->whereNull('department_id'))
                        ->orWhereHas('employee', fn ($eq) => $eq->whereNotIn('department_id', $crewDepartmentIds));
                });

            $crewCount = (clone $crewQuery)->count();
            $officeCount = (clone $officeQuery)->count();

            $this->line("Company #{$company->id} ({$company->name})");
            $this->line('  Crew roots: '.($crewRoots === [] ? 'none' : implode(', ', $crewRoots)));
            $this->line("  Crew contracts: {$crewCount}");
            $this->line("  Office contracts: {$officeCount}");

            if ($dryRun) {
                $totalCrew += $crewCount;
                $totalOffice += $officeCount;

                continue;
            }

            DB::transaction(function () use ($crewQuery, $officeQuery): void {
                (clone $crewQuery)->update([
                    'payroll_category' => PayrollCategory::Crew->value,
                    'updated_at' => now(),
                ]);

                (clone $officeQuery)->update([
                    'payroll_category' => PayrollCategory::Office->value,
                    'updated_at' => now(),
                ]);
            });

            $totalCrew += $crewCount;
            $totalOffice += $officeCount;
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'Backfill complete.');
        $this->info("Total crew: {$totalCrew}");
        $this->info("Total office: {$totalOffice}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<int>, 1: list<string>}
     */
    private function crewDepartmentIdsForCompany(int $companyId): array
    {
        $departments = Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id', 'name', 'code'])
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'parent_id' => $department->parent_id !== null ? (int) $department->parent_id : null,
                'name' => (string) $department->name,
                'code' => $department->code,
            ])
            ->all();

        $crewRootIds = [];
        $crewRootLabels = [];

        foreach ($departments as $department) {
            if ($department['parent_id'] !== null) {
                continue;
            }

            if (! $this->isCrewRootDepartment($department)) {
                continue;
            }

            $crewRootIds[] = $department['id'];
            $crewRootLabels[] = $department['name'];
        }

        $crewDepartmentIds = [];

        foreach ($crewRootIds as $rootId) {
            $crewDepartmentIds = array_merge(
                $crewDepartmentIds,
                DepartmentDescendantIds::includingSelf($rootId, $departments),
            );
        }

        return [array_values(array_unique($crewDepartmentIds)), $crewRootLabels];
    }

    /**
     * @param  array{id: int, parent_id: int|null, name: string, code: string|null}  $department
     */
    private function isCrewRootDepartment(array $department): bool
    {
        $candidates = array_filter([
            strtolower(trim($department['name'])),
            $department['code'] !== null ? strtolower(trim($department['code'])) : null,
        ]);

        foreach ($candidates as $value) {
            if (in_array($value, self::CREW_ROOT_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    private function payrollCategoryColumnExists(): bool
    {
        return DB::getSchemaBuilder()->hasColumn('employee_contracts', 'payroll_category');
    }
}
