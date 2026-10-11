<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\Company;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CandidateOfferDateValidation
{
    /**
     * Storage timezone established for all datetime columns across the application.
     */
    public static function storageTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

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
     * - Normalized to application storage timezone before returning.
     */
    public static function resolveAndValidateSentAt(
        int|Company $company,
        RecruitmentCandidateOffer $offer,
        mixed $sentAtInput,
    ): CarbonImmutable {
        $companyTimezone = CompanyTimezone::forCompany($company);
        $storageTimezone = self::storageTimezone();

        $sentAtInCompany = self::resolveEventTimestamp($sentAtInput, $companyTimezone, 'sent_at');
        $today = CarbonImmutable::now($companyTimezone)->startOfDay();
        $now = CarbonImmutable::now($companyTimezone);

        $sentDay = $sentAtInCompany->startOfDay();

        if ($sentDay->greaterThan($today) || $sentAtInCompany->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages([
                'sent_at' => 'The actual sent date cannot be in the future.',
            ]);
        }

        if ($offer->offer_date !== null) {
            $offerDay = self::parseDateOnly($offer->offer_date, $companyTimezone, 'offer_date');

            if ($sentDay->lessThan($offerDay)) {
                throw ValidationException::withMessages([
                    'sent_at' => 'The actual sent date must be on or after the offer date.',
                ]);
            }
        }

        return $sentAtInCompany->setTimezone($storageTimezone);
    }

    /**
     * Resolves and validates the actual accepted timestamp.
     *
     * - Acceptance date must be >= sent date (or offer date if unsent).
     * - Acceptance date cannot be in the future relative to the company's current date/time.
     * - Normalized to application storage timezone before returning.
     */
    public static function resolveAndValidateAcceptedAt(
        int|Company $company,
        RecruitmentCandidateOffer $offer,
        mixed $acceptedAtInput,
    ): CarbonImmutable {
        $companyTimezone = CompanyTimezone::forCompany($company);
        $storageTimezone = self::storageTimezone();

        $acceptedAtInCompany = self::resolveEventTimestamp($acceptedAtInput, $companyTimezone, 'accepted_at');
        $today = CarbonImmutable::now($companyTimezone)->startOfDay();
        $now = CarbonImmutable::now($companyTimezone);

        $acceptedDay = $acceptedAtInCompany->startOfDay();

        if ($acceptedDay->greaterThan($today) || $acceptedAtInCompany->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages([
                'accepted_at' => 'The acceptance date cannot be in the future.',
            ]);
        }

        $referenceDay = null;
        $referenceLabel = null;

        if ($offer->sent_at !== null) {
            // Convert stored reference timestamp explicitly to company timezone before comparing local calendar dates.
            $referenceDay = CarbonImmutable::instance($offer->sent_at)
                ->setTimezone($companyTimezone)
                ->startOfDay();
            $referenceLabel = 'date the offer was sent';
        } elseif ($offer->offer_date !== null) {
            $referenceDay = self::parseDateOnly($offer->offer_date, $companyTimezone, 'offer_date');
            $referenceLabel = 'offer date';
        }

        if ($referenceDay !== null && $acceptedDay->lessThan($referenceDay)) {
            throw ValidationException::withMessages([
                'accepted_at' => "The acceptance date must be on or after the {$referenceLabel}.",
            ]);
        }

        return $acceptedAtInCompany->setTimezone($storageTimezone);
    }

    /**
     * Resolves and validates the actual rejected timestamp.
     *
     * - Rejection date must be >= sent date (or offer date if unsent).
     * - Rejection date cannot be in the future relative to the company's current date/time.
     * - Normalized to application storage timezone before returning.
     */
    public static function resolveAndValidateRejectedAt(
        int|Company $company,
        RecruitmentCandidateOffer $offer,
        mixed $rejectedAtInput,
    ): CarbonImmutable {
        $companyTimezone = CompanyTimezone::forCompany($company);
        $storageTimezone = self::storageTimezone();

        $rejectedAtInCompany = self::resolveEventTimestamp($rejectedAtInput, $companyTimezone, 'rejected_at');
        $today = CarbonImmutable::now($companyTimezone)->startOfDay();
        $now = CarbonImmutable::now($companyTimezone);

        $rejectedDay = $rejectedAtInCompany->startOfDay();

        if ($rejectedDay->greaterThan($today) || $rejectedAtInCompany->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages([
                'rejected_at' => 'The rejection date cannot be in the future.',
            ]);
        }

        $referenceDay = null;
        $referenceLabel = null;

        if ($offer->sent_at !== null) {
            // Convert stored reference timestamp explicitly to company timezone before comparing local calendar dates.
            $referenceDay = CarbonImmutable::instance($offer->sent_at)
                ->setTimezone($companyTimezone)
                ->startOfDay();
            $referenceLabel = 'date the offer was sent';
        } elseif ($offer->offer_date !== null) {
            $referenceDay = self::parseDateOnly($offer->offer_date, $companyTimezone, 'offer_date');
            $referenceLabel = 'offer date';
        }

        if ($referenceDay !== null && $rejectedDay->lessThan($referenceDay)) {
            throw ValidationException::withMessages([
                'rejected_at' => "The rejection date must be on or after the {$referenceLabel}.",
            ]);
        }

        return $rejectedAtInCompany->setTimezone($storageTimezone);
    }

    /**
     * Resolves and validates the actual joining date and event timestamp.
     *
     * - Date must be on or after offer acceptance date.
     * - Date cannot be in the future relative to the company's current date/time.
     * - Historical valid dates are accepted.
     *
     * @return array{actual_joining_date: string, joined_at: CarbonImmutable}
     */
    public static function resolveAndValidateActualJoiningDate(
        int|Company $company,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $acceptedOffer,
        mixed $joiningDateInput,
    ): array {
        $companyTimezone = CompanyTimezone::forCompany($company);
        $storageTimezone = self::storageTimezone();

        if (! filled($joiningDateInput)) {
            throw ValidationException::withMessages([
                'actual_joining_date' => 'The actual joining date is required.',
            ]);
        }

        $parsedDate = self::parseDateOnly((string) $joiningDateInput, $companyTimezone, 'actual_joining_date');
        $today = CarbonImmutable::now($companyTimezone)->startOfDay();

        if ($parsedDate->greaterThan($today)) {
            throw ValidationException::withMessages([
                'actual_joining_date' => 'The actual joining date cannot be in the future.',
            ]);
        }

        if ($acceptedOffer->accepted_at !== null) {
            $acceptedDay = CarbonImmutable::instance($acceptedOffer->accepted_at)
                ->setTimezone($companyTimezone)
                ->startOfDay();

            if ($parsedDate->lessThan($acceptedDay)) {
                throw ValidationException::withMessages([
                    'actual_joining_date' => 'The actual joining date must be on or after the offer acceptance date.',
                ]);
            }
        } elseif ($acceptedOffer->offer_date !== null) {
            $offerDay = self::parseDateOnly($acceptedOffer->offer_date, $companyTimezone, 'offer_date');

            if ($parsedDate->lessThan($offerDay)) {
                throw ValidationException::withMessages([
                    'actual_joining_date' => 'The actual joining date must be on or after the offer date.',
                ]);
            }
        }

        $nowCompany = CarbonImmutable::now($companyTimezone);
        $joinedAt = $nowCompany->setTimezone($storageTimezone);

        return [
            'actual_joining_date' => $parsedDate->toDateString(),
            'joined_at' => $joinedAt,
        ];
    }

    /**
     * Extracts a pure calendar date string (Y-m-d) before any timezone interpretation.
     */
    public static function extractDateOnlyString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $str, $matches)) {
            return $matches[1];
        }

        return $str;
    }

    public static function parseDateOnly(mixed $value, string $timezone, string $field): CarbonImmutable
    {
        $extracted = self::extractDateOnlyString($value);

        if ($extracted === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $extracted)) {
            throw ValidationException::withMessages([
                $field => 'The date format is invalid.',
            ]);
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $extracted, $timezone);
        } catch (Throwable) {
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

        if ($input instanceof CarbonInterface) {
            return CarbonImmutable::instance($input)->setTimezone($timezone);
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
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $fieldName => 'The date format is invalid.',
            ]);
        }
    }
}
