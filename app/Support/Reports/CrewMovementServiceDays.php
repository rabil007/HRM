<?php

namespace App\Support\Reports;

use App\Models\CrewAssignmentPhase;
use App\Support\Payroll\CrewTimeline\CrewPayableDateOwnership;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Allocated payable calendar dates for operational service totals.
 *
 * A phase handoff often closes one phase and opens the next on the same
 * company-local date. Each recorded phase still includes that date, but the
 * date is owned by one category: onsite, then sign-off standby, then sign-on
 * standby. Excluded phases (P0 Pre-Arrival, P4 Home / Redeployment; exclusive
 * legacy Travel In if present) never take a date from a payable category. The
 * owned date is not subtracted again from every other period.
 *
 * Standby is split for Crew Movement History reporting:
 * - Pre-Joining Standby = sign-on standby dates (P1A Pre-Joining Standby + P1B Training)
 * - Demobilisation Standby = sign-off standby dates (P3)
 * - Total Standby = Pre-Joining + Demobilisation (missing bucket = 0)
 *
 * Dates after company-local today are not counted. The payroll preview is a
 * separate calculation and is not clamped here, so a future actual end can
 * make the payroll preview larger than these operational totals.
 *
 * Same-timestamp and midnight boundaries use the company-local calendar date
 * of each actual timestamp. A zero-length phase on one calendar date is one
 * eligible day for that phase, then subject to the same single-date priority.
 */
final class CrewMovementServiceDays
{
    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{
     *     recorded: bool,
     *     future_actual_only: bool,
     *     has_future_actual: bool,
     *     vessel_service_days: int|null,
     *     pre_joining_standby_days: int|null,
     *     demobilisation_standby_days: int|null,
     *     standby_service_days: int|null
     * }
     */
    public static function summarize(
        Collection $phases,
        string $timezone,
        ?CarbonInterface $today = null,
        ?int $employeeId = null,
        ?int $companyId = null,
        ?int $assignmentId = null,
    ): array {
        $analysis = CrewPayableDateOwnership::analyze(
            $phases,
            $timezone,
            $today,
            clampToToday: true,
            employeeId: $employeeId,
            companyId: $companyId,
            assignmentId: $assignmentId,
        );

        return [
            'recorded' => $analysis['recorded'],
            'future_actual_only' => $analysis['future_actual_only'],
            'has_future_actual' => $analysis['has_future_actual'],
            'vessel_service_days' => $analysis['vessel_service_days'],
            'pre_joining_standby_days' => $analysis['sign_on_standby_days'],
            'demobilisation_standby_days' => $analysis['sign_off_standby_days'],
            // Total Standby is whichever standby buckets are present (missing = 0).
            'standby_service_days' => $analysis['standby_service_days'],
        ];
    }
}
