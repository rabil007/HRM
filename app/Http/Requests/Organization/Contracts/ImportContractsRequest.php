<?php

namespace App\Http\Requests\Organization\Contracts;

use App\Enums\PayrollCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportContractsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('contracts.import');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payroll_category' => ['required', Rule::in(PayrollCategory::values())],
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:5120',
            ],
        ];
    }
}
