<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportCrewTimesheetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can('payroll.crew_timesheets.import')
            || $this->user()?->can('payroll.crew_timesheets.create'));
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
                'mimes:xlsx,xls,csv',
                'max:5120',
            ],
        ];
    }
}
