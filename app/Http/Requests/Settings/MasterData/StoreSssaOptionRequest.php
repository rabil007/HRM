<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreSssaOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'unique:sssa_options,name'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
