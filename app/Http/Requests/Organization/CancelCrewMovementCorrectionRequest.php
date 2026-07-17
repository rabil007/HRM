<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class CancelCrewMovementCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('decision_notes')) {
            $this->merge([
                'decision_notes' => trim((string) $this->input('decision_notes')),
            ]);
        }
    }
}
