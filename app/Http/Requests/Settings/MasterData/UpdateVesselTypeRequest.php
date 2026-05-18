<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVesselTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vesselTypeId = (int) $this->route('vessel_type')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:vessel_types,name,{$vesselTypeId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
