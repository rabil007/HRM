<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewVoidCrewAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('crew_operations.assignments.void');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assignment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'assignment_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignment_ids.required' => 'At least one crew assignment must be selected.',
            'assignment_ids.min' => 'At least one crew assignment must be selected.',
        ];
    }
}
