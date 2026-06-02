<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSssaOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sssaOptionId = (int) $this->route('sssa_option')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:sssa_options,name,{$sssaOptionId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
