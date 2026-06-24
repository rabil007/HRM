<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportWpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.wps.export');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'period_id' => [
                'required',
                'integer',
                Rule::exists('payroll_periods', 'id')->where('company_id', $companyId),
            ],
            'format' => ['required', 'string', Rule::in(['sif', 'xlsx'])],
        ];
    }
}
