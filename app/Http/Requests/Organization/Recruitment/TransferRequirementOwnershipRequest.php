<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequirementOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.requirements.transfer_ownership');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('assigned_to') === '' || $this->input('assigned_to') === null) {
            $this->merge(['assigned_to' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'created_by' => ['required', 'integer', Rule::exists('users', 'id')],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array{created_by: int, assigned_to: int|null, reason: string}
     */
    public function ownershipData(): array
    {
        $validated = $this->validated();

        return [
            'created_by' => (int) $validated['created_by'],
            'assigned_to' => filled($validated['assigned_to'] ?? null)
                ? (int) $validated['assigned_to']
                : null,
            'reason' => trim((string) $validated['reason']),
        ];
    }
}
