<?php

namespace App\Http\Requests\Organization\Contracts;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\EmployeeContract;
use App\Support\Contracts\SalaryRevisionEffectiveMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContractSalaryRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $normalized = SalaryRevisionEffectiveMonth::tryNormalize($this->input('effective_from'));

        if ($normalized !== null) {
            $this->merge([
                'effective_from' => $normalized,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var EmployeeContract|null $contract */
            $contract = $this->route('employeeContract');

            if (! $contract instanceof EmployeeContract) {
                return;
            }

            $this->validateUniqueMonth($validator, $contract);
            $this->validatePositiveAmounts($validator, $contract);
        });
    }

    private function validateUniqueMonth(Validator $validator, EmployeeContract $contract): void
    {
        if ($validator->errors()->has('effective_from')) {
            return;
        }

        $effectiveFrom = SalaryRevisionEffectiveMonth::tryNormalize($this->input('effective_from'));

        if ($effectiveFrom === null) {
            return;
        }

        /** @var ContractSalaryRevision|null $currentRevision */
        $currentRevision = $this->route('salaryRevision');

        $exists = ContractSalaryRevision::query()
            ->where('contract_id', $contract->id)
            ->whereDate('effective_from', $effectiveFrom)
            ->when(
                $currentRevision instanceof ContractSalaryRevision,
                fn ($query) => $query->whereKeyNot($currentRevision->id),
            )
            ->exists();

        if ($exists) {
            $validator->errors()->add(
                'effective_from',
                'A salary revision already exists for this month.',
            );
        }
    }

    private function validatePositiveAmounts(Validator $validator, EmployeeContract $contract): void
    {
        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();

        $amounts = match (true) {
            $category === PayrollCategory::Crew && $structure === ContractSalaryStructure::Daily => [
                $this->input('basic_salary'),
                $this->input('supplementary_allowance'),
                $this->input('site_allowance'),
            ],
            default => [
                $this->input('basic_salary'),
                $this->input('housing_allowance'),
                $this->input('transport_allowance'),
                $this->input('other_allowances'),
            ],
        };

        $hasPositive = collect($amounts)->contains(
            fn (mixed $amount): bool => $amount !== null && $amount !== '' && (float) $amount > 0,
        );

        if (! $hasPositive) {
            $validator->errors()->add(
                'basic_salary',
                'At least one salary component with an amount greater than zero is required.',
            );
        }
    }
}
