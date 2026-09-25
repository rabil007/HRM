<?php

namespace App\Support\CrewMovements;

use App\Exceptions\CrewMovementException;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Ensures actual movement timestamps are not recorded in the future.
 *
 * Planning timestamps (planned join/sign-off/travel) are validated elsewhere.
 * When the company testing override is enabled, the future-date restriction is bypassed;
 * sequencing and invariant checks remain authoritative.
 */
final class CrewActualMovementTimestampGuard
{
    public function assertNotFuture(int $companyId, CarbonInterface $occurredAt): void
    {
        if (CrewOperationsSettings::allowFutureActualMovementDates($companyId)) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $now = Carbon::now($timezone);

        if ($occurredAt->gt($now)) {
            throw CrewMovementException::make(
                'Actual movement events cannot be recorded in the future.',
                'occurred_at_in_future',
            );
        }
    }
}
