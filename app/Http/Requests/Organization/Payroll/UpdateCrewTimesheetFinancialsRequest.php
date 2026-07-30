<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCrewTimesheetFinancialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can('payroll.crew_timesheets.create')
            || $this->user()?->can('payroll.crew_timesheets.update'));
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['unpaid_leave_days', 'remarks'] as $field) {
            if ($this->exists($field) && ($this->input($field) === '' || $this->input($field) === null)) {
                $normalized[$field] = null;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unpaid_leave_days' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'overtime_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additional_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function financialData(): array
    {
        return $this->safe()->only([
            'unpaid_leave_days',
            'overtime_hours',
            'overtime_amount',
            'additional_amount',
            'deduction_amount',
            'remarks',
        ]);
    }
}
