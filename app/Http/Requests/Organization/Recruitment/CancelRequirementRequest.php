<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CancelRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.cancel') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cancellation_reason') && ! $this->has('reason')) {
            $this->merge(['reason' => $this->input('cancellation_reason')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
