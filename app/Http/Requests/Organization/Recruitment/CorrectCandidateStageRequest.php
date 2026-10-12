<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectCandidateStageRequest extends FormRequest
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
            'to_stage' => ['required', 'string', Rule::in([
                CandidateStage::Applied->value,
                CandidateStage::Screening->value,
            ])],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
            'expected_outcome' => ['nullable', 'string', 'max:30'],
        ];
    }
}
