<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GeneratePayslipsFromSalarySheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.payslips.generate');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240',
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }
}
