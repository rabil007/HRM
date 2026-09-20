<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkVoidCrewAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('crew_operations.assignments.void')) {
            return false;
        }

        if ($this->boolean('delete_sea_service') && ! $user->can('sea_services.delete')) {
            return false;
        }

        if ($this->boolean('delete_training') && ! $user->can('training.delete')) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('void_reason');

        if (is_string($reason)) {
            $this->merge([
                'void_reason' => trim($reason),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assignment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'assignment_ids.*' => ['integer', 'distinct'],
            'delete_sea_service' => ['sometimes', 'boolean'],
            'delete_training' => ['sometimes', 'boolean'],
            'void_reason' => ['required', 'string', 'max:2000'],
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
            'void_reason.required' => 'A void reason is required.',
        ];
    }
}
