<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnsureEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['required', 'string', 'max:200'],
            'employee_profile_template_id' => [
                'nullable',
                'integer',
                Rule::exists('employee_profile_templates', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ];
    }
}
