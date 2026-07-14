<?php

namespace App\Http\Requests\Organization\Contracts;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\EmployeeContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContractSalaryRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
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
        });
    }
}
