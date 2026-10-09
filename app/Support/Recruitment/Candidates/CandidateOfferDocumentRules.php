<?php

namespace App\Support\Recruitment\Candidates;

final class CandidateOfferDocumentRules
{
    /**
     * @return list<string>
     */
    public static function uploadRules(bool $required = false): array
    {
        $mimes = implode(',', CandidateOfferStorage::ALLOWED_MIMES);

        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'mimes:'.$mimes,
            'max:'.CandidateOfferStorage::MAX_SIZE_KB,
        ]));
    }
}
