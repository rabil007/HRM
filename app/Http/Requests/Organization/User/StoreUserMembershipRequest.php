<?php

namespace App\Http\Requests\Organization\User;

use App\Support\Users\UserMembershipAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()) {
            return false;
        }

        UserMembershipAccess::assertActiveCompany($this);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = UserMembershipAccess::assertActiveCompany($this);

        return [
            'status' => ['nullable', 'in:active,inactive'],
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('spatie_roles', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ];
    }
}
