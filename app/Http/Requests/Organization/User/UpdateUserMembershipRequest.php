<?php

namespace App\Http\Requests\Organization\User;

use App\Models\Company;
use App\Models\User;
use App\Support\Users\UserMembershipAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()) {
            return false;
        }

        $company = $this->route('company');
        $target = $this->route('user');

        if (! $company instanceof Company || ! $target instanceof User) {
            return false;
        }

        UserMembershipAccess::assertRouteCompanyIsActive($this, $company);
        UserMembershipAccess::assertMembershipInCompany($target, (int) $company->id);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = UserMembershipAccess::assertActiveCompany($this);

        return [
            'status' => ['required', 'in:active,inactive'],
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('spatie_roles', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ];
    }
}
