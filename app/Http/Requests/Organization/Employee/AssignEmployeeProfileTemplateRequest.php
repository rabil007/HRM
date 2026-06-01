<?php

namespace App\Http\Requests\Organization\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignEmployeeProfileTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_profile_template_id' => [
                'required',
                'integer',
                Rule::exists('employee_profile_templates', 'id')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $employee = $this->route('employee');

            if (! $employee instanceof Employee) {
                return;
            }

            if ($employee->employee_profile_template_id !== null) {
                $validator->errors()->add(
                    'employee_profile_template_id',
                    'This employee already has a profile template assigned.',
                );
            }
        });
    }
}
