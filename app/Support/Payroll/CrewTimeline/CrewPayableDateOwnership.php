<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignmentPhase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One payable category per employee per company-local calendar date.
 *
 * Priority matches automatic Crew Timesheet allocation: On Vessel, then
 * demobilisation standby, then pre-joining standby (including training),
 * then excluded phases. Equal priority keeps the lower phase id.
 * A shared handoff date is reassigned, not subtracted from every period.
 */
final class CrewPayableDateOwnership
{
    /**
     * Phase statuses that may contribute payable calendar days.
     * Matches automatic Crew Timesheet preparation.
     *
     * @return list<CrewPhaseStatus>
     */
    public static function eligibleStatuses(): array
    {
        return [
            CrewPhaseStatus::Active,
            CrewPhaseStatus::Completed,
        ];
    }

    public static function isEligibleStatus(CrewPhaseStatus $status): bool
    {
        return in_array($status, self::eligibleStatuses(), true);
    }

    public static function isExcludedPhase(CrewPhaseCode $phaseCode): bool
    {
        return (new CrewPhasePayCategoryResolver)->resolve($phaseCode) === CrewTimesheetPayCategory::Excluded;
    }

    /**
     * @return list<string>
     */
    public static function eligibleStatusValues(): array
    {
        return array_map(
            static fn (CrewPhaseStatus $status): string => $status->value,
            self::eligibleStatuses(),
        );
    }

    public static function compare(int $leftPriority, int $leftPhaseId, int $rightPriority, int $rightPhaseId): int
    {
        $priority = $rightPriority <=> $leftPriority;

        if ($priority !== 0) {
            return $priority;
        }

        return $leftPhaseId <=> $rightPhaseId;
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{
     *     recorded: bool,
     *     future_actual_only: bool,
     *     has_future_actual: bool,
     *     vessel_service_days: int|null,
     *     standby_service_days: int|null,
     *     sign_on_standby_days: int|null,
     *     sign_off_standby_days: int|null,
     *     allocated_days_by_phase: array<int, int>,
     *     countable_phase_ids: array<int, true>,
     *     future_only_phase_ids: array<int, true>,
     *     dates_by_category: array<string, list<string>>
     * }
     */
    public static function analyze(
        Collection $phases,
        string $timezone,
        ?CarbonInterface $today = null,
        bool $clampToToday = true,
        ?int $employeeId = null,
        ?int $companyId = null,
        ?int $assignmentId = null,
    ): array {
        $today ??= now($timezone);
        $todayDate = self::localDate($today, $timezone);
        $resolver = new CrewPhasePayCategoryResolver;
        $recorded = false;
        $countedADate = false;
        $hasFutureActual = false;

        /** @var array<int, true> $futureOnlyPhaseIds */
        $futureOnlyPhaseIds = [];

        /** @var array<int, true> $countablePhaseIds */
        $countablePhaseIds = [];

        /** @var list<array{phase_id: int, assignment_id: int, employee_id: int, phase_code: CrewPhaseCode, from: string, to: string}> $intervals */
        $intervals = [];

        foreach ($phases as $phase) {
            if (! $phase instanceof CrewAssignmentPhase) {
                continue;
            }

            if ($companyId !== null && isset($phase->company_id) && (int) $phase->company_id !== $companyId) {
                continue;
            }

            // Planned / cancelled / corrected phases never inflate payable service days.
            // Completed standby on a later-cancelled assignment remains eligible.
            if (! self::isEligibleStatus($phase->status)) {
                continue;
            }

            if ($phase->actual_start_at === null) {
                continue;
            }

            $end = match (true) {
                $phase->actual_end_at !== null => $phase->actual_end_at,
                $phase->status === CrewPhaseStatus::Active => $today,
                default => null,
            };

            if ($end === null || $end->lt($phase->actual_start_at)) {
                continue;
            }

            $from = self::localDate($phase->actual_start_at, $timezone);
            $to = self::localDate($end, $timezone);
            $phaseEmployeeId = $employeeId ?? (
                $phase->relationLoaded('assignment')
                    ? (int) ($phase->assignment?->employee_id ?? 0)
                    : 0
            );
            $phaseAssignmentId = (int) $phase->crew_assignment_id;
            $reportsThisAssignment = $assignmentId === null || $phaseAssignmentId === $assignmentId;

            if ($reportsThisAssignment) {
                $recorded = true;
            }

            if ($from->gt($todayDate)) {
                if ($reportsThisAssignment) {
                    $hasFutureActual = true;
                    $futureOnlyPhaseIds[(int) $phase->id] = true;
                }

                if ($clampToToday) {
                    continue;
                }
            } elseif ($to->gt($todayDate)) {
                if ($reportsThisAssignment) {
                    $hasFutureActual = true;
                }

                if ($clampToToday) {
                    $to = $todayDate;
                }
            }

            if ($from->gt($to)) {
                continue;
            }

            if (! $from->gt($todayDate) && $reportsThisAssignment) {
                $countedADate = true;
                $countablePhaseIds[(int) $phase->id] = true;
            }

            $intervals[] = [
                'phase_id' => (int) $phase->id,
                'assignment_id' => $phaseAssignmentId,
                'employee_id' => $phaseEmployeeId,
                'phase_code' => $phase->phase_code,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ];
        }

        $winners = self::allocate($intervals, $resolver);
        $allocatedDaysByPhase = [];
        $datesByCategory = [
            CrewTimesheetPayCategory::SignOnStandby->value => [],
            CrewTimesheetPayCategory::Onsite->value => [],
            CrewTimesheetPayCategory::SignOffStandby->value => [],
        ];
        $vessel = 0;
        $signOn = 0;
        $signOff = 0;

        foreach ($winners as $winner) {
            if ($assignmentId !== null && $winner['assignment_id'] !== $assignmentId) {
                continue;
            }

            $allocatedDaysByPhase[$winner['phase_id']] = ($allocatedDaysByPhase[$winner['phase_id']] ?? 0) + 1;
            $category = $winner['category']->value;

            if (array_key_exists($category, $datesByCategory)) {
                $datesByCategory[$category][] = $winner['date'];
            }

            if ($winner['category'] === CrewTimesheetPayCategory::Onsite) {
                $vessel++;
            } elseif ($winner['category'] === CrewTimesheetPayCategory::SignOnStandby) {
                $signOn++;
            } elseif ($winner['category'] === CrewTimesheetPayCategory::SignOffStandby) {
                $signOff++;
            }
        }

        foreach ($datesByCategory as $category => $dates) {
            sort($dates);
            $datesByCategory[$category] = $dates;
        }

        if (! $recorded) {
            return [
                'recorded' => false,
                'future_actual_only' => false,
                'has_future_actual' => false,
                'vessel_service_days' => null,
                'standby_service_days' => null,
                'sign_on_standby_days' => null,
                'sign_off_standby_days' => null,
                'allocated_days_by_phase' => [],
                'countable_phase_ids' => [],
                'future_only_phase_ids' => [],
                'dates_by_category' => [
                    CrewTimesheetPayCategory::SignOnStandby->value => [],
                    CrewTimesheetPayCategory::Onsite->value => [],
                    CrewTimesheetPayCategory::SignOffStandby->value => [],
                ],
            ];
        }

        return [
            'recorded' => true,
            'future_actual_only' => ! $countedADate,
            'has_future_actual' => $hasFutureActual,
            'vessel_service_days' => $vessel,
            'standby_service_days' => $signOn + $signOff,
            'sign_on_standby_days' => $signOn,
            'sign_off_standby_days' => $signOff,
            'allocated_days_by_phase' => $allocatedDaysByPhase,
            'countable_phase_ids' => $countablePhaseIds,
            'future_only_phase_ids' => $futureOnlyPhaseIds,
            'dates_by_category' => $datesByCategory,
        ];
    }

    /**
     * @param  list<array{
     *     phase_id: int,
     *     assignment_id: int,
     *     employee_id: int,
     *     phase_code: CrewPhaseCode,
     *     from: string,
     *     to: string
     * }>  $intervals
     * @return array<string, array{
     *     phase_id: int,
     *     assignment_id: int,
     *     employee_id: int,
     *     phase_code: CrewPhaseCode,
     *     category: CrewTimesheetPayCategory,
     *     date: string
     * }>
     */
    public static function allocate(array $intervals, ?CrewPhasePayCategoryResolver $resolver = null): array
    {
        $resolver ??= new CrewPhasePayCategoryResolver;

        /** @var array<string, list<array{phase_id: int, assignment_id: int, employee_id: int, phase_code: CrewPhaseCode, category: CrewTimesheetPayCategory, priority: int, date: string}>> $claimsByDay */
        $claimsByDay = [];

        foreach ($intervals as $interval) {
            $from = CarbonImmutable::parse($interval['from'])->startOfDay();
            $to = CarbonImmutable::parse($interval['to'])->startOfDay();

            if ($to->lt($from)) {
                continue;
            }

            $category = $resolver->resolve($interval['phase_code']);
            $priority = $resolver->priority($category);

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $key = $interval['employee_id'].'|'.$day->toDateString();
                $claimsByDay[$key][] = [
                    'phase_id' => $interval['phase_id'],
                    'assignment_id' => $interval['assignment_id'],
                    'employee_id' => $interval['employee_id'],
                    'phase_code' => $interval['phase_code'],
                    'category' => $category,
                    'priority' => $priority,
                    'date' => $day->toDateString(),
                ];
            }
        }

        $winners = [];

        foreach ($claimsByDay as $key => $claims) {
            usort($claims, fn (array $left, array $right): int => self::compare(
                $left['priority'],
                $left['phase_id'],
                $right['priority'],
                $right['phase_id'],
            ));

            $winner = $claims[0];
            unset($winner['priority']);
            $winners[$key] = $winner;
        }

        return $winners;
    }

    /**
     * @param  list<array{
     *     phase_code: CrewPhaseCode,
     *     actual_start_at: ?CarbonInterface,
     *     actual_end_at: ?CarbonInterface,
     *     status: CrewPhaseStatus
     * }>  $phases
     * @return list<array{
     *     allocated_service_days: int|null,
     *     service_days_label: string|null,
     *     calendar_span_days: int|null
     * }>
     */
    public static function presentPlainPhases(
        array $phases,
        string $timezone,
        ?CarbonInterface $today = null,
    ): array {
        $today ??= now($timezone);
        $todayDate = self::localDate($today, $timezone);
        $resolver = new CrewPhasePayCategoryResolver;

        /** @var list<array{phase_id: int, assignment_id: int, employee_id: int, phase_code: CrewPhaseCode, from: string, to: string}> $intervals */
        $intervals = [];
        $rows = [];

        foreach ($phases as $index => $phase) {
            $phaseId = $index + 1;
            $start = $phase['actual_start_at'] ?? null;
            $end = $phase['actual_end_at'] ?? null;
            $status = $phase['status'];
            $eligible = self::isEligibleStatus($status);
            $open = $end === null && $status === CrewPhaseStatus::Active;
            $resolvedEnd = $end ?? ($open ? $today : null);
            $calendarSpan = null;
            $futureOnly = false;
            $countable = false;

            if ($start !== null && $end !== null && ! $end->lt($start)) {
                $spanFrom = self::localDate($start, $timezone);
                $spanTo = self::localDate($end, $timezone);
                $calendarSpan = (int) $spanFrom->diffInDays($spanTo) + 1;
            }

            if (
                $eligible
                && $start !== null
                && $resolvedEnd !== null
                && ! $resolvedEnd->lt($start)
            ) {
                $from = self::localDate($start, $timezone);
                $to = self::localDate($resolvedEnd, $timezone);

                if ($from->gt($todayDate)) {
                    $futureOnly = true;
                } else {
                    if ($to->gt($todayDate)) {
                        $to = $todayDate;
                    }

                    if ($from->lte($to)) {
                        $countable = true;
                        $intervals[] = [
                            'phase_id' => $phaseId,
                            'assignment_id' => 1,
                            'employee_id' => 1,
                            'phase_code' => $phase['phase_code'],
                            'from' => $from->toDateString(),
                            'to' => $to->toDateString(),
                        ];
                    }
                }
            }

            $rows[$index] = [
                'phase_id' => $phaseId,
                'phase_code' => $phase['phase_code'],
                'open' => $open,
                'future_only' => $futureOnly,
                'countable' => $countable,
                'calendar_span_days' => $calendarSpan,
            ];
        }

        $counts = [];

        foreach (self::allocate($intervals, $resolver) as $winner) {
            $counts[$winner['phase_id']] = ($counts[$winner['phase_id']] ?? 0) + 1;
        }

        $presented = [];

        foreach ($rows as $index => $row) {
            $allocated = match (true) {
                self::isExcludedPhase($row['phase_code']) => null,
                $row['future_only'] => 0,
                $row['countable'] => $counts[$row['phase_id']] ?? 0,
                default => null,
            };

            $presented[$index] = [
                'allocated_service_days' => $allocated,
                'service_days_label' => self::serviceDayLabel(
                    $row['phase_code'],
                    $allocated,
                    $row['open'],
                    $row['future_only'],
                ),
                'calendar_span_days' => $row['calendar_span_days'],
            ];
        }

        return $presented;
    }

    public static function serviceDayLabel(
        CrewPhaseCode $phaseCode,
        ?int $days,
        bool $open = false,
        bool $futureOnly = false,
    ): ?string {
        if ($futureOnly) {
            return 'Future actual';
        }

        if ($days === null) {
            return null;
        }

        $noun = match ($phaseCode) {
            CrewPhaseCode::JoinStandby, CrewPhaseCode::Training => $open ? 'Standby service to date' : 'Standby service',
            CrewPhaseCode::DemobStandby => $open ? 'Sign-off standby to date' : 'Sign-off standby',
            CrewPhaseCode::OnVessel => $open ? 'Vessel service to date' : 'Vessel service',
            default => null,
        };

        if ($noun === null) {
            return null;
        }

        $unit = $days === 1 ? 'day' : 'days';

        return "{$noun}: {$days} {$unit}";
    }

    private static function localDate(CarbonInterface $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $value->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();
    }
}
