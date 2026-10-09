<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Http\Requests\Organization\Recruitment\Concerns\ValidatesCandidateOfferFields;
use App\Support\Recruitment\Candidates\CandidateOfferDocumentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PrepareCandidateOfferRequest extends FormRequest
{
    use ValidatesCandidateOfferFields;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.offer.prepare');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->offerDetailRules(true), [
            'offer_document' => CandidateOfferDocumentRules::uploadRules(false),
            'lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
            'expected_outcome' => ['nullable', 'string', 'max:30'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->withOfferDateConsistency($validator);
    }
}
