<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreCrewMovementCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crew_operations.corrections.request');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'crew_assignment_phase_id' => ['required', 'integer'],
            'proposed_values' => ['required', 'array', 'min:1'],
            'proposed_values.*' => ['nullable'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }
    }
}
