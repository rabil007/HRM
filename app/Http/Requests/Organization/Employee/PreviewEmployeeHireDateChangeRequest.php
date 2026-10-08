<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Foundation\Http\FormRequest;

class PreviewEmployeeHireDateChangeRequest extends FormRequest
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
            'hire_date' => ['nullable', 'date'],
        ];
    }
}
