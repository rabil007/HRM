<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReligionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $religionId = (int) $this->route('religion')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:religions,name,{$religionId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
