<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Support\Recruitment\Candidates\CandidateOfferDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

class AcceptCandidateOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.offer.decide');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accepted_at' => ['nullable', 'date'],
            'acceptance_document' => CandidateOfferDocumentRules::uploadRules(false),
            'lock_version' => ['required', 'integer', 'min:0'],
            'offer_lock_version' => ['required', 'integer', 'min:0'],
            'expected_stage' => ['nullable', 'string', 'max:30'],
            'expected_offer_status' => ['nullable', 'string', 'max:30'],
        ];
    }
}
