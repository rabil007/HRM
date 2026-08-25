<?php

namespace App\Http\Requests\Organization\User;

use App\Concerns\PasswordValidationRules;
use App\Rules\UniqueUserEmail;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', new UniqueUserEmail],
            'password' => $this->passwordRules(),
            'avatar' => ['nullable', 'file', 'image', 'max:2048'],
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('spatie_roles', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'employee_id' => ['nullable', 'integer', ActiveCompanyEmployeeRule::exists($companyId)],
            'use_employee_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
