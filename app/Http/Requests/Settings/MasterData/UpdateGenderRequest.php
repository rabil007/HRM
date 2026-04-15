<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $genderId = (int) $this->route('gender')?->id;

        return [
            'name' => ['required', 'string', 'max:60', "unique:genders,name,{$genderId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
