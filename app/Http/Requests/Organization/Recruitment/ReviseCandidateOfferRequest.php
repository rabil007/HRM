<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Http\Requests\Organization\Recruitment\Concerns\ValidatesCandidateOfferFields;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReviseCandidateOfferRequest extends FormRequest
{
    use ValidatesCandidateOfferFields;

    public function authorize(): bool
    {
        $this->enforceTenantScope();

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
            $candidate = $this->enforceTenantScope();

            if ($candidate->stage === CandidateStage::Joined) {
                $validator->errors()->add(
                    'candidate',
                    'Offers cannot be revised while candidate is in Joined stage. Undo joined first if revision is required.'
                );
            }
        });
    }

    private function enforceTenantScope(): RecruitmentCandidate
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        abort_if($companyId <= 0, 404);

        $candidateParam = $this->route('candidate');
        $candidate = $candidateParam instanceof RecruitmentCandidate
            ? $candidateParam
            : RecruitmentCandidate::query()
                ->where('company_id', $companyId)
                ->where('id', $candidateParam)
                ->first();

        abort_if($candidate === null || (int) $candidate->company_id !== $companyId, 404);

        $offerParam = $this->route('offer');
        $offer = $offerParam instanceof RecruitmentCandidateOffer
            ? $offerParam
            : RecruitmentCandidateOffer::query()
                ->where('company_id', $companyId)
                ->where('id', $offerParam)
                ->first();

        abort_if(
            $offer === null
            || (int) $offer->company_id !== $companyId
            || (int) $offer->recruitment_candidate_id !== (int) $candidate->id,
            404
        );

        return $candidate;
    }
}
