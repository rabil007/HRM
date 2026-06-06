<?php

namespace App\Http\Requests\Hikvision;

use Illuminate\Foundation\Http\FormRequest;

class LinkHikvisionPersonEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hikvision.persons.link');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }
}
