<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class RejectCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.move');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'lock_version' => ['nullable', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
            'expected_outcome' => ['nullable', 'string', 'max:30'],
        ];
    }
}
