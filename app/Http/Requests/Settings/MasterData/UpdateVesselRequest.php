<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVesselRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vesselId = (int) $this->route('vessel')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:vessels,name,{$vesselId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
