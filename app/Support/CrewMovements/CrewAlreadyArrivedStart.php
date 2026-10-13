<?php

namespace App\Support\CrewMovements;

use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;

/**
 * Optional Start Assignment shortcut: create Active P2A Join Standby directly
 * when the crew member has already arrived, without inventing a completed P0.
 */
final class CrewAlreadyArrivedStart
{
    public const REQUIRED_MESSAGE = 'Actual Arrival Date is required when Already Arrived is selected.';

    public const FUTURE_MESSAGE = 'Actual Arrival Date cannot be in the future.';

    public const EXPLANATION = 'The crew member has already arrived. The assignment will start directly in Join Standby (P2A), skipping Pre-Mobilisation.';

    public static function isEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Validate a date-only Actual Arrival Date in the company timezone.
     * Today is allowed; any company-local calendar day after today is rejected.
     */
    public static function validateActualArrivalDate(
        Validator $validator,
        int $companyId,
        mixed $rawDate,
        string $errorKey,
    ): void {
        if ($rawDate === null || $rawDate === '') {
            $validator->errors()->add($errorKey, self::REQUIRED_MESSAGE);

            return;
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);

        try {
            $arrival = Carbon::parse((string) $rawDate, $timezone)->startOfDay();
        } catch (\Throwable) {
            $validator->errors()->add($errorKey, self::REQUIRED_MESSAGE);

            return;
        }

        $today = Carbon::now($timezone)->startOfDay();

        if ($arrival->gt($today)) {
            $validator->errors()->add($errorKey, self::FUTURE_MESSAGE);
        }
    }

    /**
     * Resolve the operational P2A actual start from a required Actual Arrival Date.
     */
    public static function resolveActualArrivalAt(int $companyId, mixed $rawDate): CarbonInterface
    {
        if ($rawDate === null || $rawDate === '') {
            throw new \InvalidArgumentException(self::REQUIRED_MESSAGE);
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);

        return Carbon::parse((string) $rawDate, $timezone)->startOfDay();
    }
}
