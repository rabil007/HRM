<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Http\Requests\Organization\Recruitment\Concerns\ValidatesCandidateOfferFields;
use App\Models\RecruitmentCandidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReviseCandidateOfferRequest extends FormRequest
{
    use ValidatesCandidateOfferFields;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.offer.revise')
            && (bool) $this->user()?->can('recruitment.candidates.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'reason' => ['required', 'string', 'max:2000'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'offer_lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
            'expected_offer_status' => ['nullable', 'string', 'max:30'],
        ], $this->offerDetailRules(false));
    }

    public function withValidator(Validator $validator): void
    {
        $this->withOfferDateConsistency($validator);

        $validator->after(function (Validator $validator): void {
            $candidate = $this->route('candidate');
            if (is_numeric($candidate) || is_string($candidate)) {
                $candidate = RecruitmentCandidate::query()->find($candidate);
            }

            if ($candidate instanceof RecruitmentCandidate && $candidate->stage === CandidateStage::Joined) {
                $validator->errors()->add(
                    'candidate',
                    'Offers cannot be revised while candidate is in Joined stage. Undo joined first if revision is required.'
                );
            }
        });
    }
}
