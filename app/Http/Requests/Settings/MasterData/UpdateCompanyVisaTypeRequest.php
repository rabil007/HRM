<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyVisaTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyVisaTypeId = (int) $this->route('company_visa_type')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:company_visa_types,name,{$companyVisaTypeId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
