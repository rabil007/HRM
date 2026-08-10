<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VoidCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $assignment = $this->route('assignment');

        if (! $assignment instanceof CrewAssignment) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        if ((int) $assignment->company_id !== $companyId) {
            abort(404);
        }

        return $user->can('void', $assignment);
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
            'void_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'void_reason.required' => 'A void reason is required.',
        ];
    }
}
