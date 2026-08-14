<?php

namespace App\Http\Requests\Organization\User;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = $user instanceof User ? (int) $user->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => $this->optionalPasswordRules(),
            'avatar' => ['nullable', 'file', 'image', 'max:2048'],
            'use_employee_avatar' => ['sometimes', 'boolean'],
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(function ($query) use ($companyId, $userId): void {
                    $query->where('company_id', $companyId)
                        ->where(function ($inner) use ($userId): void {
                            $inner->where('status', 'active');

                            if ($userId !== null) {
                                $inner->orWhere('user_id', $userId);
                            }
                        })
                        ->whereNull('deleted_at');
                }),
            ],
            'role_id' => ['nullable', 'integer', 'exists:spatie_roles,id'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
        ];
    }
}
