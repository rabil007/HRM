<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientId = (int) $this->route('client')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:clients,name,{$clientId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
