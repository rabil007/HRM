<?php

namespace App\Http\Requests\Hikvision;

use Illuminate\Foundation\Http\FormRequest;

class StoreHikvisionPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hikvision.persons.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'group_id' => ['nullable', 'string', 'max:255'],
            'person_code' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
