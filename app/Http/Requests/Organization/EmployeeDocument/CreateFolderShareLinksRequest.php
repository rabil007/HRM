<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class CreateFolderShareLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct'],
            'password' => ['nullable', 'string', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'can_download' => ['sometimes', 'boolean'],
            'can_upload' => ['sometimes', 'boolean'],
        ];
    }
}
