<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CandidateCorrectJoiningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can('recruitment.candidates.manage')
            && $this->user()?->can('recruitment.candidates.joining.confirm'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
        ];
    }
}
