<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeUserRequest extends FormRequest
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
            'role_id' => [
                'required',
                'integer',
                Rule::exists('spatie_roles', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
