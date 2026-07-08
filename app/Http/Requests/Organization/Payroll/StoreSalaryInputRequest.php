<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Models\Employee;
use App\Models\SalaryInputType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalaryInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('payroll.salary_inputs.create') ?? false)
            || ($this->user()?->can('payroll.periods.update') ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('notes') === '') {
            $this->merge(['notes' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')),
            ],
            'salary_input_type_id' => [
                'required',
                'integer',
                Rule::exists('salary_input_types', 'id')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('status', 'active')),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail((int) $this->validated('employee_id'));
    }

    public function salaryInputType(): SalaryInputType
    {
        return SalaryInputType::query()->findOrFail((int) $this->validated('salary_input_type_id'));
    }

    /**
     * @return array{salary_input_type_id: int, amount: float, notes: string|null}
     */
    public function salaryInputData(): array
    {
        $validated = $this->validated();

        return [
            'salary_input_type_id' => (int) $validated['salary_input_type_id'],
            'amount' => (float) $validated['amount'],
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
