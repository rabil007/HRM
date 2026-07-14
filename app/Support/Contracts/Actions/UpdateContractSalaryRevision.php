<?php

namespace App\Support\Contracts\Actions;

use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Payroll\ContractSalaryComponentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateContractSalaryRevision
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
    ) {}

    /**
     * @param  array<string, float|int|string|null>  $amountsByColumn
     */
    public function handle(
        EmployeeContract $contract,
        ContractSalaryRevision $revision,
        array $amountsByColumn,
        string $effectiveFrom,
        ?string $reason = null,
    ): ContractSalaryRevision {
        abort_unless(
            $revision->contract_id === $contract->id
            && $revision->company_id === $contract->company_id,
            404,
        );

        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();
        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $structure);
        $lines = [];

        foreach ($columnMap as $column => $componentCode) {
            $rawAmount = $amountsByColumn[$column] ?? null;

            if ($rawAmount === null || $rawAmount === '') {
                continue;
            }

            $amount = round((float) $rawAmount, 2);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'component_code' => $componentCode,
                'component_name' => $componentCode->label(),
                'rate_type' => $componentCode->defaultRateTypeFor($category, $structure)->value,
                'amount' => $amount,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'basic_salary' => 'At least one salary component with an amount greater than zero is required.',
            ]);
        }

        return DB::transaction(function () use (
            $contract,
            $revision,
            $effectiveFrom,
            $reason,
            $lines,
        ): ContractSalaryRevision {
            $revision->update([
                'effective_from' => $effectiveFrom,
                'reason' => $reason,
            ]);

            ContractSalaryRevisionLine::query()
                ->where('revision_id', $revision->id)
                ->delete();

            foreach ($lines as $line) {
                ContractSalaryRevisionLine::query()->create([
                    'company_id' => $contract->company_id,
                    'revision_id' => $revision->id,
                    ...$line,
                ]);
            }

            $this->mirrorLatestRevision->handle($contract->fresh());

            return $revision->fresh(['lines']);
        });
    }
}
