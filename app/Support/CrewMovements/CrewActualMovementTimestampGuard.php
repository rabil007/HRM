<?php

namespace App\Support\CrewMovements;

use App\Exceptions\CrewMovementException;
use App\Models\Company;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Ensures actual movement timestamps are not recorded in the future.
 *
 * Planning timestamps (planned join/sign-off/travel) are validated elsewhere.
 */
final class CrewActualMovementTimestampGuard
{
    public function assertNotFuture(int $companyId, CarbonInterface $occurredAt): void
    {
        $timezone = $this->companyTimezone($companyId);
        $now = Carbon::now($timezone);

        if ($occurredAt->gt($now)) {
            throw CrewMovementException::make(
                'Actual movement events cannot be recorded in the future.',
                'occurred_at_in_future',
            );
        }
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
    }
}
