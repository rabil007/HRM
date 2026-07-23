<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ShowCrewPayrollGenerationPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.periods.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'excluded_employee_ids' => ['sometimes', 'array'],
            'excluded_employee_ids.*' => ['integer'],
        ];
    }
}
