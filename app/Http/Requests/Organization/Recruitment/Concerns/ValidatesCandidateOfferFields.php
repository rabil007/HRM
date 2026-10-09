<?php

namespace App\Http\Requests\Organization\Recruitment\Concerns;

use App\Models\Currency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesCandidateOfferFields
{
    /**
     * @return array<string, mixed>
     */
    protected function offerDetailRules(bool $requireCore = true): array
    {
        $presence = $requireCore ? 'required' : 'sometimes';

        return [
            'salary_amount' => [$presence, 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
            'salary_currency_code' => [
                $presence,
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'proposed_joining_date' => [$presence, 'date'],
            'offer_date' => [$presence, 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:offer_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function withOfferDateConsistency(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $joining = $this->input('proposed_joining_date');
            $offerDate = $this->input('offer_date');

            if (! is_string($joining) || ! is_string($offerDate) || $joining === '' || $offerDate === '') {
                return;
            }

            if (strtotime($joining) < strtotime($offerDate)) {
                $validator->errors()->add(
                    'proposed_joining_date',
                    'The proposed joining date must be on or after the offer date.',
                );
            }

            $code = strtoupper((string) $this->input('salary_currency_code', ''));
            if ($code !== '' && ! Currency::query()->where('code', $code)->where('is_active', true)->exists()) {
                $validator->errors()->add('salary_currency_code', 'Select an active currency.');
            }
        });
    }
}
