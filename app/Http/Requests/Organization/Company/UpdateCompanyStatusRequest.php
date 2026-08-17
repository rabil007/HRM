<?php

namespace App\Http\Requests\Organization\Company;

use App\Models\Company;
use App\Support\Companies\CompanyRegistryAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()) {
            return false;
        }

        $company = $this->route('company');

        if ($company instanceof Company) {
            CompanyRegistryAccess::assertRouteCompanyIsActive($this, $company);
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
