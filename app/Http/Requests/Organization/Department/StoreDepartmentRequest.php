<?php

namespace App\Http\Requests\Organization\Department;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
