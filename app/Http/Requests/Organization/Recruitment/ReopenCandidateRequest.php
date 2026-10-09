<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ReopenCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.move')
            && (bool) $this->user()?->can('recruitment.candidates.manage');
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
        ];
    }
}
