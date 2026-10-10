<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CandidateJoiningReadinessRequest extends FormRequest
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
            'expected_joining_date' => ['nullable', 'date'],
            'joining_readiness_status' => ['required', Rule::enum(CandidateJoiningReadinessStatus::class)],
            'joining_readiness_notes' => ['nullable', 'string', 'max:2000'],
            'joining_blocker_notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
        ];
    }
}
