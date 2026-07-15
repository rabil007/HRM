<?php

namespace App\Support\Contracts\Actions;

use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\ContractSalaryComponentCatalog;

final class MirrorLatestContractSalaryRevision
{
    public function __construct(
        private readonly SyncContractSalaryComponentsFromContract $syncSalaryComponents,
    ) {}

    public function handle(EmployeeContract $contract): void
    {
        $latest = ContractSalaryRevision::query()
            ->where('contract_id', $contract->id)
            ->whereDate('effective_from', '<=', today()->toDateString())
            ->with('lines')
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if ($latest === null) {
            return;
        }

        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();
        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $structure);
        $mirrorAttributes = [];

        foreach ($columnMap as $column => $componentCode) {
            $line = $latest->lines->first(
                fn (ContractSalaryRevisionLine $item): bool => $item->component_code === $componentCode,
            );

            $mirrorAttributes[$column] = $line !== null && (float) $line->amount > 0
                ? round((float) $line->amount, 2)
                : null;
        }

        foreach ([
            'basic_salary',
            'housing_allowance',
            'transport_allowance',
            'other_allowances',
            'supplementary_allowance',
            'site_allowance',
        ] as $column) {
            if (! array_key_exists($column, $columnMap)) {
                $mirrorAttributes[$column] = null;
            }
        }

        $contract->update($mirrorAttributes);
        $this->syncSalaryComponents->handle($contract->fresh());
    }
}
