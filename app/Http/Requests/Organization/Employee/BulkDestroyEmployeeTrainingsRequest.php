<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Foundation\Http\FormRequest;

class BulkDestroyEmployeeTrainingsRequest extends FormRequest
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
            'training_ids' => ['required', 'array', 'min:1'],
            'training_ids.*' => ['integer', 'distinct'],
        ];
    }
}
