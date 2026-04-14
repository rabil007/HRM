<?php

namespace App\Http\Requests\Organization\Position;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
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
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:200'],
            'grade' => ['nullable', 'string', 'max:50'],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
