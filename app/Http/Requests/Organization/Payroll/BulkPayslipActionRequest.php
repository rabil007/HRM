<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPayslipActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return match ($this->route()?->getActionMethod()) {
            'generate' => (bool) $this->user()?->can('payroll.payslips.generate'),
            'email' => (bool) $this->user()?->can('payroll.payslips.email'),
            default => false,
        };
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'record_ids' => ['nullable', 'array', 'required_without:period_id'],
            'record_ids.*' => [
                'integer',
                Rule::exists('payroll_records', 'id')->where('company_id', $companyId),
            ],
            'period_id' => [
                'nullable',
                'integer',
                'required_without:record_ids',
                Rule::exists('payroll_periods', 'id')->where('company_id', $companyId),
            ],
        ];
    }
}
