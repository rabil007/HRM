<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewMovementAction;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Authoritative past/current vs future decision for crew movement submissions.
 *
 * Client-supplied mode flags are ignored. Comparison uses full company-local
 * timestamps against the current instant in the company timezone.
 */
final class ResolveCrewMovementTiming
{
    /**
     * Whether the submitted movement instant is strictly after company "now".
     */
    public static function isFuture(
        CarbonInterface|string|null $occurredAt,
        int $companyId,
        ?string $timezone = null,
    ): bool {
        if ($occurredAt === null || $occurredAt === '') {
            return false;
        }

        $tz = $timezone ?? CompanyTimezone::forCompanyId($companyId);
        $instant = $occurredAt instanceof CarbonInterface
            ? $occurredAt->copy()->timezone($tz)
            : Carbon::parse((string) $occurredAt, $tz);

        return $instant->gt(Carbon::now($tz));
    }

    /**
     * Future + schedulable action → create a scheduled movement instead of performing now.
     */
    public static function shouldSchedule(
        CrewMovementAction $action,
        CarbonInterface|string|null $occurredAt,
        int $companyId,
        ?string $timezone = null,
    ): bool {
        if (! CrewSchedulableMovementActions::isSchedulable($action)) {
            return false;
        }

        return self::isFuture($occurredAt, $companyId, $timezone);
    }
}
