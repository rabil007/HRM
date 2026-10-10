<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CandidateConfirmJoinedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.joining.confirm');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actual_joining_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
        ];
    }
}
