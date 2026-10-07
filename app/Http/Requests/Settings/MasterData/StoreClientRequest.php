<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }
    }

    public function rules(): array
    {
        $nameRules = ['required', 'string', 'max:120'];

        // JSON quick-create reuses case-insensitive matches in the controller.
        if (! $this->wantsJson()) {
            $nameRules[] = 'unique:clients,name';
        }

        return [
            'name' => $nameRules,
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
