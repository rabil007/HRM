<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalaryInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('payroll.salary_inputs.update') ?? false)
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
            'salary_input_type_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('salary_input_types', 'id')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('status', 'active')),
            ],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{salary_input_type_id?: int, amount?: float, notes?: string|null}
     */
    public function salaryInputData(): array
    {
        $validated = $this->validated();
        $data = [];

        if (array_key_exists('salary_input_type_id', $validated)) {
            $data['salary_input_type_id'] = (int) $validated['salary_input_type_id'];
        }

        if (array_key_exists('amount', $validated)) {
            $data['amount'] = (float) $validated['amount'];
        }

        if (array_key_exists('notes', $validated)) {
            $data['notes'] = $validated['notes'];
        }

        return $data;
    }
}
