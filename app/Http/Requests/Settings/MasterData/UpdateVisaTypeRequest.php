<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVisaTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $visaTypeId = (int) $this->route('visa_type')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:visa_types,name,{$visaTypeId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
