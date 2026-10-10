<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\Company;
use App\Models\RecruitmentCandidateOffer;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CandidateOfferDateValidation
{
    /**
     * Validates that the effective proposed joining and expiry dates are on or after the offer date.
     */
    public static function validateOfferDraftDates(
        int|Company $company,
        ?string $offerDate,
        ?string $joiningDate,
        ?string $expiryDate = null,
    ): void {
        $timezone = CompanyTimezone::forCompany($company);

        if (filled($offerDate) && filled($joiningDate)) {
            $parsedOffer = self::parseDateOnly((string) $offerDate, $timezone, 'offer_date');
            $parsedJoining = self::parseDateOnly((string) $joiningDate, $timezone, 'proposed_joining_date');

            if ($parsedJoining->lessThan($parsedOffer)) {
                throw ValidationException::withMessages([
                    'proposed_joining_date' => 'The proposed joining date must be on or after the offer date.',
                ]);
            }
        }

        if (filled($offerDate) && filled($expiryDate)) {
            $parsedOffer = self::parseDateOnly((string) $offerDate, $timezone, 'offer_date');
            $parsedExpiry = self::parseDateOnly((string) $expiryDate, $timezone, 'expiry_date');

            if ($parsedExpiry->lessThan($parsedOffer)) {
                throw ValidationException::withMessages([
                    'expiry_date' => 'The expiry date must be on or after the offer date.',
                ]);
            }
        }
    }

    /**
     * Resolves and validates the actual sent timestamp.
     *
     * - Sent date must be >= offer date.
     * - Actual sent date cannot be in the future relative to the company's current date/time.
     */
    public static function resolveAndValidateSentAt(
        int|Company $company,
        RecruitmentCandidateOffer $offer,
        mixed $sentAtInput,
    ): CarbonImmutable {
        $timezone = CompanyTimezone::forCompany($company);
        $sentAt = self::resolveEventTimestamp($sentAtInput, $timezone, 'sent_at');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $now = CarbonImmutable::now($timezone);

        $sentDay = $sentAt->startOfDay();

        if ($sentDay->greaterThan($today) || $sentAt->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages([
                'sent_at' => 'The actual sent date cannot be in the future.',
            ]);
        }

        if ($offer->offer_date !== null) {
            $offerDay = CarbonImmutable::parse($offer->offer_date, $timezone)->startOfDay();

            if ($sentDay->lessThan($offerDay)) {
                throw ValidationException::withMessages([
                    'sent_at' => 'The actual sent date must be on or after the offer date.',
                ]);
            }
        }

        return $sentAt;
    }

    /**
     * Resolves and validates the actual accepted timestamp.
     *
     * - Acceptance date must be >= sent date (or offer date if unsent).
     * - Acceptance date cannot be in the future relative to the company's current date/time.
     */
    public static function resolveAndValidateAcceptedAt(
        int|Company $company,
        RecruitmentCandidateOffer $offer,
        mixed $acceptedAtInput,
    ): CarbonImmutable {
        $timezone = CompanyTimezone::forCompany($company);
        $acceptedAt = self::resolveEventTimestamp($acceptedAtInput, $timezone, 'accepted_at');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $now = CarbonImmutable::now($timezone);

        $acceptedDay = $acceptedAt->startOfDay();

        if ($acceptedDay->greaterThan($today) || $acceptedAt->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages([
                'accepted_at' => 'The acceptance date cannot be in the future.',
            ]);
        }

        $referenceDay = null;
        $referenceLabel = null;

        if ($offer->sent_at !== null) {
            $referenceDay = CarbonImmutable::parse($offer->sent_at, $timezone)->startOfDay();
            $referenceLabel = 'date the offer was sent';
        } elseif ($offer->offer_date !== null) {
            $referenceDay = CarbonImmutable::parse($offer->offer_date, $timezone)->startOfDay();
            $referenceLabel = 'offer date';
        }

        if ($referenceDay !== null && $acceptedDay->lessThan($referenceDay)) {
            throw ValidationException::withMessages([
                'accepted_at' => "The acceptance date must be on or after the {$referenceLabel}.",
            ]);
        }

        return $acceptedAt;
    }

    /**
     * Resolves and validates the actual rejected timestamp.
     *
     * - Rejection date must be >= sent date (or offer date if unsent).
     * - Rejection date cannot be in the future relative to the company's current date/time.
     */
    public static function resolveAndValidateRejectedAt(
        int|Company $company,
        RecruitmentCandidateOffer $offer,
        mixed $rejectedAtInput,
    ): CarbonImmutable {
        $timezone = CompanyTimezone::forCompany($company);
        $rejectedAt = self::resolveEventTimestamp($rejectedAtInput, $timezone, 'rejected_at');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $now = CarbonImmutable::now($timezone);

        $rejectedDay = $rejectedAt->startOfDay();

        if ($rejectedDay->greaterThan($today) || $rejectedAt->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages([
                'rejected_at' => 'The rejection date cannot be in the future.',
            ]);
        }

        $referenceDay = null;
        $referenceLabel = null;

        if ($offer->sent_at !== null) {
            $referenceDay = CarbonImmutable::parse($offer->sent_at, $timezone)->startOfDay();
            $referenceLabel = 'date the offer was sent';
        } elseif ($offer->offer_date !== null) {
            $referenceDay = CarbonImmutable::parse($offer->offer_date, $timezone)->startOfDay();
            $referenceLabel = 'offer date';
        }

        if ($referenceDay !== null && $rejectedDay->lessThan($referenceDay)) {
            throw ValidationException::withMessages([
                'rejected_at' => "The rejection date must be on or after the {$referenceLabel}.",
            ]);
        }

        return $rejectedAt;
    }

    private static function parseDateOnly(string $value, string $timezone, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'The date format is invalid.',
            ]);
        }
    }

    private static function resolveEventTimestamp(mixed $input, string $timezone, string $fieldName): CarbonImmutable
    {
        if (! filled($input)) {
            return CarbonImmutable::now($timezone);
        }

        if ($input instanceof CarbonImmutable) {
            return $input->timezone($timezone);
        }

        $inputStr = trim((string) $input);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputStr)) {
                $parsedDate = CarbonImmutable::parse($inputStr, $timezone)->startOfDay();
                $today = CarbonImmutable::now($timezone)->startOfDay();

                if ($parsedDate->equalTo($today)) {
                    return CarbonImmutable::now($timezone);
                }

                return $parsedDate;
            }

            return CarbonImmutable::parse($inputStr, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $fieldName => 'The date format is invalid.',
            ]);
        }
    }
}
