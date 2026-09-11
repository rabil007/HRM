<?php

namespace App\Http\Requests\Organization\User;

use App\Support\Employees\ActiveCompanyEmployeeRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('spatie_roles', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'employee_id' => [
                'nullable',
                'integer',
                ActiveCompanyEmployeeRule::exists($companyId)->whereNull('user_id'),
            ],
        ];
    }
}
