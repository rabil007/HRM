<?php

namespace App\Http\Requests\Organization\User;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => $this->passwordRules(),
            'avatar' => ['nullable', 'file', 'image', 'max:2048'],
            'role_id' => ['nullable', 'integer', 'exists:spatie_roles,id'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'employee_id' => ['nullable', 'integer'],
            'use_employee_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
