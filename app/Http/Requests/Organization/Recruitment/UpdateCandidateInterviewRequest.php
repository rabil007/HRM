<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\CandidateInterviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCandidateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'interview_scheduled_at' => ['nullable', 'date'],
            'interviewer_user_id' => ['nullable', 'integer'],
            'external_interviewer_name' => ['nullable', 'string', 'max:200'],
            'interview_mode' => ['nullable', 'string', Rule::in(CandidateInterviewMode::values())],
            'interview_location' => ['nullable', 'string', 'max:300'],
            'interview_feedback' => ['nullable', 'string', 'max:10000'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
        ];
    }
}
