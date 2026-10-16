<?php

namespace App\Support\Reports;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewAccommodation\CrewAccommodationService;
use App\Support\CrewMovements\CrewArrivalResolver;
use App\Support\CrewMovements\CrewAssignmentLinkChain;
use App\Support\CrewMovements\CrewDateProvenance;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewTourProgress;
use App\Support\Payroll\CrewTimeline\CrewPayableDateOwnership;
use App\Support\Positions\CrewAssignmentPositionPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewMovementHistoryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CrewAssignment $assignment): array
    {
        $companyId = (int) $assignment->company_id;
        $employeeId = (int) $assignment->employee_id;
        $timezone = (string) ($assignment->company?->timezone ?? config('app.timezone', 'UTC'));
        $today = now($timezone);
        $phases = $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => ! isset($phase->company_id) || (int) $phase->company_id === $companyId)
            ->sortBy('sequence')
            ->values();
        $allocationPhases = self::allocationPhases($assignment, $phases, $companyId, $employeeId);
        $operational = CrewPayableDateOwnership::analyze(
            $allocationPhases,
            $timezone,
            $today,
            clampToToday: true,
            employeeId: $employeeId,
            companyId: $companyId,
            assignmentId: (int) $assignment->id,
        );
        $tour = (new CrewTourProgress)->forAssignment($assignment, null, $timezone);
        $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $tour);
        $summaries = [];

        foreach (CrewPhaseCode::cases() as $phaseCode) {
            $summaries[$phaseCode->value] = self::phaseSummary(
                $phases,
                $phaseCode,
                $timezone,
                $today,
                $operational['allocated_days_by_phase'],
                $operational['future_only_phase_ids'],
            );
        }

        $assignmentEnd = $assignment->closed_at;
        if ($assignmentEnd === null && $assignment->status === CrewAssignmentStatus::Active) {
            $assignmentEnd = $today;
        }

        $assignmentDays = CrewMovementHistoryDuration::elapsedDays(
            $assignment->started_at,
            $assignmentEnd,
            $timezone,
        );

        $onVessel = $summaries[CrewPhaseCode::OnVessel->value];
        $training = $summaries[CrewPhaseCode::Training->value];
        $serviceDays = [
            'recorded' => $operational['recorded'],
            'future_actual_only' => $operational['future_actual_only'],
            'has_future_actual' => $operational['has_future_actual'],
            'vessel_service_days' => $operational['vessel_service_days'],
            'pre_joining_standby_days' => $operational['sign_on_standby_days'],
            'demobilisation_standby_days' => $operational['sign_off_standby_days'],
            'standby_service_days' => $operational['standby_service_days'],
        ];

        if ($serviceDays['has_future_actual'] || $serviceDays['future_actual_only']) {
            $warnings[] = [
                'code' => 'future_actual',
                'severity' => 'warning',
                'label' => 'Future Actual',
                'message' => $serviceDays['future_actual_only']
                    ? 'Recorded actual movement starts after today and is not counted as completed service.'
                    : 'A later actual movement starts after today. Historical service days are kept; the future portion is not counted.',
                'date' => null,
            ];
        }

        $payrollDays = CrewMovementHistoryPayrollDays::summarize(
            $allocationPhases,
            $timezone,
            $today,
            $employeeId,
            $companyId,
            (int) $assignment->id,
        );
        $approvedCorrections = $assignment->relationLoaded('corrections')
            ? $assignment->corrections
                ->where('company_id', $companyId)
                ->where('status', CrewMovementCorrectionStatus::Approved)
            : collect();
        $pendingCorrections = $assignment->relationLoaded('corrections')
            ? $assignment->corrections
                ->where('company_id', $companyId)
                ->where('status', CrewMovementCorrectionStatus::Pending)
            : collect();

        $plannedJoin = CrewDateProvenance::plannedJoin($assignment, $timezone);
        $plannedArrival = CrewDateProvenance::plannedArrival($assignment, $timezone);
        $actualArrival = CrewDateProvenance::actualArrival($assignment, $timezone);
        $plannedSignoff = CrewDateProvenance::plannedSignoff($assignment, $timezone);
        $plannedTravelHome = CrewDateProvenance::plannedTravel($assignment, $timezone);
        $plannedTravelIn = [
            'start' => null,
            'origin' => null,
            'origin_label' => null,
        ];
        $travelIn = self::emptyPhaseFlatten();
        $readyToJoin = self::emptyPhaseFlatten();
        $homeRedeploy = self::flatten($summaries[CrewPhaseCode::HomeRedeploy->value]);
        $trainingHistory = self::trainingHistory($phases, $timezone, $companyId);
        $phaseTimeline = self::phaseTimeline(
            $phases,
            $timezone,
            $today,
            $operational['allocated_days_by_phase'],
            $operational['future_only_phase_ids'],
        );
        $modernTimeline = $phaseTimeline;
        $legacyTimeline = [];
        $accommodation = self::accommodationHistory($assignment, $timezone);
        $linked = self::linkedAssignments($assignment, $timezone);
        $startingCheckpoint = self::startingCheckpoint($phases, $timezone);

        $actualJoinAt = self::firstPeriodDateTime($onVessel, 'start_at');
        $actualDisembarkationAt = self::lastCompletedEndAt($onVessel);

        $firstHomePhase = $phases->first(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::HomeRedeploy);
        $nextList = $linked['next'] ?? [];
        $firstNext = $nextList[0] ?? null;
        $hasLinkedNextRedeployment = $firstNext !== null && in_array($firstNext['source'] ?? null, ['vessel_transfer', 'redeployment'], true);

        $hasCompletedDemobBeforeHome = false;
        if ($firstHomePhase !== null) {
            $priorPhase = $phases
                ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->sequence < $firstHomePhase->sequence)
                ->sortByDesc('sequence')
                ->first();

            $hasCompletedDemobBeforeHome = $priorPhase !== null
                && $priorPhase->phase_code === CrewPhaseCode::DemobStandby
                && ($priorPhase->status === CrewPhaseStatus::Completed || $priorPhase->actual_end_at !== null);
        }

        $outcome = null;
        $outcomeLabel = null;
        $actualReturnHomeAt = null;
        $redeployedAt = null;
        $isRedeployedDirectly = false;

        if ($firstHomePhase !== null && $hasCompletedDemobBeforeHome) {
            $outcome = 'returned_home';
            $outcomeLabel = 'Returned Home';
            $actualReturnHomeAt = self::dateTime($firstHomePhase->actual_start_at, $timezone);
        } elseif ($hasLinkedNextRedeployment) {
            $outcome = 'redeployed';
            $outcomeLabel = 'Redeployed';
            $redeployedAt = $firstNext['started_at'] ?? self::dateTime($assignment->closed_at, $timezone);
            $isRedeployedDirectly = $firstHomePhase === null;
        } elseif ($firstHomePhase !== null) {
            $outcome = 'home_redeploy';
            $outcomeLabel = 'Home / Redeploy';
        }

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'employee' => [
                'id' => $assignment->employee?->id,
                'employee_no' => $assignment->employee?->employee_no,
                'name' => $assignment->employee?->name,
            ],
            'position' => CrewAssignmentPositionPresenter::option($assignment, $companyId),
            'vessel' => self::option($assignment->vessel, $companyId),
            'client' => self::option($assignment->client, $companyId),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'current_phase' => ($assignment->currentPhase && (! isset($assignment->currentPhase->company_id) || (int) $assignment->currentPhase->company_id === $companyId)) ? [
                'code' => $assignment->currentPhase->phase_code->value,
                'label' => $assignment->currentPhase->phase_code->label(),
                'status' => $assignment->currentPhase->status->value,
            ] : null,
            'starting_phase_code' => $startingCheckpoint['code'],
            'starting_phase_label' => $startingCheckpoint['label'],
            'starting_phase_started_at' => $startingCheckpoint['started_at'],
            'source' => $assignment->source,
            'source_label' => self::sourceLabel($assignment->source),
            'remarks' => $assignment->remarks,
            'created_at' => self::dateTime($assignment->created_at, $timezone),
            'updated_at' => self::dateTime($assignment->updated_at, $timezone),
            'planned_travel_in' => $plannedTravelIn['start'],
            'planned_travel_in_origin' => $plannedTravelIn['origin'],
            'planned_travel_in_origin_label' => $plannedTravelIn['origin_label'],
            'planned_arrival' => $plannedArrival['value'],
            'planned_arrival_origin' => $plannedArrival['origin'],
            'planned_arrival_origin_label' => $plannedArrival['origin_label'],
            'actual_arrival' => $actualArrival['value'],
            'actual_arrival_at' => self::dateTime(
                CrewArrivalResolver::timestamp($assignment),
                $timezone,
            ),
            'actual_arrival_origin' => $actualArrival['origin'],
            'actual_arrival_origin_label' => $actualArrival['origin_label'],
            'planned_join' => $plannedJoin['value'],
            'planned_join_origin' => $plannedJoin['origin'],
            'planned_join_origin_label' => $plannedJoin['origin_label'],
            'planned_signoff' => $plannedSignoff['value'],
            'planned_signoff_origin' => $plannedSignoff['origin'],
            'planned_signoff_origin_label' => $plannedSignoff['origin_label'],
            'planned_travel_home' => $plannedTravelHome['value'],
            'planned_travel_home_origin' => $plannedTravelHome['origin'],
            'planned_travel_home_origin_label' => $plannedTravelHome['origin_label'],
            'phases' => $summaries,
            'phase_timeline' => $phaseTimeline,
            'modern_phase_timeline' => $modernTimeline,
            'legacy_phase_timeline' => $legacyTimeline,
            'has_legacy_phases' => false,
            // API key retained for existing report/export consumers; P0 label is Pre-Arrival.
            'pre_mobilisation' => self::flatten($summaries[CrewPhaseCode::PreArrival->value]),
            'travel_in' => $travelIn,
            'join_standby' => $summaries[CrewPhaseCode::JoinStandby->value],
            'training' => [
                ...$training,
                'details' => array_values(array_filter(array_map(
                    fn (array $item): ?string => $item['summary'],
                    $trainingHistory,
                ))),
                'history' => $trainingHistory,
            ],
            'ready_to_join' => $readyToJoin,
            'on_vessel' => [
                ...$onVessel,
                'actual_join' => $onVessel['periods'][0]['start'] ?? null,
                'actual_join_at' => $actualJoinAt,
                'actual_disembarkation' => self::lastCompletedEnd($onVessel),
                'actual_disembarkation_at' => $actualDisembarkationAt,
                'to' => self::lastCompletedEnd($onVessel),
            ],
            'demob_standby' => self::flatten($summaries[CrewPhaseCode::DemobStandby->value]),
            'home_redeploy' => [
                ...$homeRedeploy,
                'outcome' => $outcome,
                'outcome_label' => $outcomeLabel,
                'actual_return_home_at' => $actualReturnHomeAt,
                'is_redeployed_directly' => $isRedeployedDirectly,
                'redeployed_at' => $redeployedAt,
            ],
            // Distinct inclusive standby calendar dates after handoff priority.
            // Pre-joining = sign-on (P1A + Training); demobilisation = sign-off (P3).
            'pre_joining_standby_days' => $serviceDays['pre_joining_standby_days'],
            'pre_joining_standby_days_label' => self::serviceDaysLabel(
                $serviceDays['pre_joining_standby_days'],
                $serviceDays['future_actual_only'],
                $serviceDays['has_future_actual'],
            ),
            'demobilisation_standby_days' => $serviceDays['demobilisation_standby_days'],
            'demobilisation_standby_days_label' => self::serviceDaysLabel(
                $serviceDays['demobilisation_standby_days'],
                $serviceDays['future_actual_only'],
                $serviceDays['has_future_actual'],
            ),
            'pre_joining_standby' => self::standbyWindow(
                $phases,
                [CrewPhaseCode::JoinStandby, CrewPhaseCode::Training],
                $operational['allocated_dates_by_phase'],
                $serviceDays['pre_joining_standby_days'],
                self::serviceDaysLabel(
                    $serviceDays['pre_joining_standby_days'],
                    $serviceDays['future_actual_only'],
                    $serviceDays['has_future_actual'],
                ),
                $timezone,
            ),
            'demobilisation_standby' => self::standbyWindow(
                $phases,
                [CrewPhaseCode::DemobStandby],
                $operational['allocated_dates_by_phase'],
                $serviceDays['demobilisation_standby_days'],
                self::serviceDaysLabel(
                    $serviceDays['demobilisation_standby_days'],
                    $serviceDays['future_actual_only'],
                    $serviceDays['has_future_actual'],
                ),
                $timezone,
            ),
            // Total Standby = Pre-Joining + Demobilisation (missing phase = 0).
            'standby_service_days' => $serviceDays['standby_service_days'],
            'standby_service_days_label' => self::serviceDaysLabel(
                $serviceDays['standby_service_days'],
                $serviceDays['future_actual_only'],
                $serviceDays['has_future_actual'],
            ),
            'vessel_service_days' => $serviceDays['vessel_service_days'],
            'vessel_service_days_label' => self::serviceDaysLabel(
                $serviceDays['vessel_service_days'],
                $serviceDays['future_actual_only'],
                $serviceDays['has_future_actual'],
            ),
            'has_future_actual' => $serviceDays['has_future_actual'],
            'assignment_started' => self::date($assignment->started_at, $timezone),
            'assignment_started_at' => self::dateTime($assignment->started_at, $timezone),
            'assignment_closed' => self::date($assignment->closed_at, $timezone),
            'assignment_closed_at' => self::dateTime($assignment->closed_at, $timezone),
            'total_assignment_days' => $assignmentDays,
            'total_assignment_days_label' => CrewMovementHistoryDuration::label($assignmentDays),
            'tour' => [
                ...$tour,
                'planned_signoff_override_reason' => $assignment->planned_signoff_override_reason,
            ],
            'accommodation_stays' => $accommodation,
            'linked_assignments' => $linked,
            'payroll_days' => $payrollDays,
            'needs_attention' => $warnings !== [],
            'warnings' => collect($warnings)->pluck('label')->values()->all(),
            'warning_details' => $warnings,
            'has_corrections' => $approvedCorrections->isNotEmpty(),
            'correction_count' => $approvedCorrections->count(),
            'last_corrected_at' => self::date(
                $approvedCorrections->sortByDesc('decided_at')->first()?->decided_at,
                $timezone,
            ),
            'has_pending_corrections' => $pendingCorrections->isNotEmpty(),
            'company_timezone' => $timezone,
        ];
    }

    /**
     * Payable phases (P1A, P1B, P2, P3) use allocated category ownership.
     * Excluded phases (P0, P4) keep their actual calendar span. A handoff date
     * can belong to a payable category without shortening the excluded phase.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @param  array<int, int>  $allocatedDaysByPhase
     * @param  array<int, true>  $futureOnlyPhaseIds
     * @return array{periods: list<array<string, mixed>>, total_days: int|null, total_days_label: string, calendar_span_days: int|null}
     */
    public static function phaseSummary(
        Collection $phases,
        CrewPhaseCode $code,
        string $timezone,
        ?CarbonInterface $today = null,
        array $allocatedDaysByPhase = [],
        array $futureOnlyPhaseIds = [],
    ): array {
        $today ??= now($timezone);
        $calendarDuration = CrewPayableDateOwnership::isExcludedPhase($code);
        $coveredDates = [];
        $hasDuration = false;
        $hasAllocatedWindow = false;
        $futureOnly = false;
        $allocatedTotal = 0;

        $periods = $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === $code)
            ->sortBy('sequence')
            ->map(function (CrewAssignmentPhase $phase) use ($timezone, $today, $calendarDuration, $allocatedDaysByPhase, $futureOnlyPhaseIds, &$coveredDates, &$hasDuration, &$hasAllocatedWindow, &$futureOnly, &$allocatedTotal): array {
                $durationEnd = match ($phase->status) {
                    CrewPhaseStatus::Completed => $phase->actual_end_at,
                    CrewPhaseStatus::Active => $today,
                    default => null,
                };
                $dates = CrewMovementHistoryDuration::inclusiveCalendarDates(
                    $phase->actual_start_at,
                    $durationEnd,
                    $timezone,
                    $today,
                );
                $calendarSpanDays = $dates === null ? null : count($dates);
                $phaseIsFutureOnly = isset($futureOnlyPhaseIds[(int) $phase->id]);
                $allocatedDays = $phaseIsFutureOnly
                    ? 0
                    : ($calendarDuration
                        ? $calendarSpanDays
                        : ($allocatedDaysByPhase[(int) $phase->id] ?? ($calendarSpanDays === null ? null : 0)));

                if ($dates !== null) {
                    $hasDuration = true;

                    foreach ($dates as $date) {
                        $coveredDates[$date] = true;
                    }
                }

                if ($phaseIsFutureOnly) {
                    $futureOnly = true;
                }

                if (is_int($allocatedDays) && ! $phaseIsFutureOnly && ! $calendarDuration) {
                    $hasAllocatedWindow = true;
                    $allocatedTotal += $allocatedDays;
                }

                return [
                    'sequence' => $phase->sequence,
                    'start' => self::date($phase->actual_start_at, $timezone),
                    'end' => self::date($phase->actual_end_at, $timezone),
                    'start_at' => self::dateTime($phase->actual_start_at, $timezone),
                    'end_at' => self::dateTime($phase->actual_end_at, $timezone),
                    'planned_start_at' => self::dateTime($phase->planned_start_at, $timezone),
                    'planned_end_at' => self::dateTime($phase->planned_end_at, $timezone),
                    'status' => $phase->status->value,
                    'days' => $phaseIsFutureOnly ? 0 : $allocatedDays,
                    'days_label' => $phaseIsFutureOnly || $allocatedDays === 0 && $calendarSpanDays === 0
                        ? CrewMovementHistoryDuration::futureActualLabel()
                        : CrewMovementHistoryDuration::serviceDayLabel($phaseIsFutureOnly ? 0 : $allocatedDays),
                    'calendar_span_days' => $calendarSpanDays,
                    'calendar_span_days_label' => $calendarSpanDays === 0
                        ? CrewMovementHistoryDuration::futureActualLabel()
                        : ($calendarSpanDays === null
                            ? null
                            : 'Movement span: '.CrewMovementHistoryDuration::serviceDayLabel($calendarSpanDays)),
                    'remarks' => $phase->remarks,
                ];
            })
            ->values()
            ->all();

        $calendarSpanTotal = count($coveredDates);
        $totalDays = match (true) {
            $calendarDuration => $hasDuration ? $calendarSpanTotal : null,
            $hasAllocatedWindow => $allocatedTotal,
            $hasDuration => 0,
            default => null,
        };
        $totalLabel = match (true) {
            $totalDays === null => CrewMovementHistoryDuration::serviceDayLabel(null),
            $futureOnly && ($calendarDuration ? $calendarSpanTotal === 0 : ! $hasAllocatedWindow) => CrewMovementHistoryDuration::futureActualLabel(),
            default => CrewMovementHistoryDuration::serviceDayLabel($totalDays),
        };

        return [
            'periods' => $periods,
            'total_days' => $hasDuration ? $totalDays : null,
            'total_days_label' => $hasDuration ? $totalLabel : CrewMovementHistoryDuration::serviceDayLabel(null),
            'calendar_span_days' => $hasDuration ? $calendarSpanTotal : null,
        ];
    }

    /**
     * @return array{periods: list<array<string, mixed>>, total_days: null, total_days_label: string, from: null, to: null}
     */
    private static function emptyPhaseFlatten(): array
    {
        return self::flatten([
            'periods' => [],
            'total_days' => null,
            'total_days_label' => CrewMovementHistoryDuration::serviceDayLabel(null),
        ]);
    }

    /**
     * @param  array{periods: list<array<string, mixed>>, total_days: int|null, total_days_label: string}  $summary
     * @return array<string, mixed>
     */
    private static function flatten(array $summary): array
    {
        return [
            ...$summary,
            'from' => $summary['periods'][0]['start'] ?? null,
            'to' => self::lastCompletedEnd($summary),
        ];
    }

    /**
     * Allocated service ranges for standby summary columns.
     *
     * From/To are contiguous dates already awarded to that phase occurrence.
     * Recorded phase timestamps stay on the phase summary and timeline.
     * A gap stays as separate ranges. A phase that won no dates keeps the
     * occurrence with no invented service range. Service-day totals stay on
     * the allocated payable count and are never recomputed from From/To.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @param  list<CrewPhaseCode>  $codes
     * @param  array<int, list<string>>  $allocatedDatesByPhase
     * @return array{
     *     periods: list<array<string, mixed>>,
     *     period_count: int,
     *     multiple: bool,
     *     recorded: bool,
     *     days: int|null,
     *     days_label: string,
     *     service_days_label: string
     * }
     */
    private static function standbyWindow(
        Collection $phases,
        array $codes,
        array $allocatedDatesByPhase,
        ?int $allocatedDays,
        string $daysLabel,
        string $timezone,
    ): array {
        $periods = [];
        $recorded = false;

        $matching = $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => in_array($phase->phase_code, $codes, true))
            ->sortBy('sequence');

        foreach ($matching as $phase) {
            $movementFrom = self::date($phase->actual_start_at, $timezone);
            $movementTo = self::date($phase->actual_end_at, $timezone);

            if ($movementFrom === null && $movementTo === null) {
                continue;
            }

            $recorded = true;
            $ongoing = $phase->status === CrewPhaseStatus::Active;
            $dates = $allocatedDatesByPhase[(int) $phase->id] ?? [];

            if ($dates === []) {
                $periods[] = [
                    'phase_id' => (int) $phase->id,
                    'phase_code' => $phase->phase_code->value,
                    'phase_label' => $phase->phase_code->label(),
                    'from' => null,
                    'to' => null,
                    'to_label' => null,
                    'ongoing' => $ongoing,
                    'status' => $phase->status->value,
                    'allocated_days' => 0,
                    'movement_from' => $movementFrom,
                    'movement_to' => $movementTo,
                ];

                continue;
            }

            $ranges = self::contiguousDateRanges($dates, $timezone);
            $lastIndex = count($ranges) - 1;

            foreach ($ranges as $index => $range) {
                $periods[] = [
                    'phase_id' => (int) $phase->id,
                    'phase_code' => $phase->phase_code->value,
                    'phase_label' => $phase->phase_code->label(),
                    'from' => $range['from'],
                    'to' => $range['to'],
                    'to_label' => $range['to'],
                    'ongoing' => $ongoing && $index === $lastIndex,
                    'status' => $phase->status->value,
                    'allocated_days' => $range['days'],
                    'movement_from' => $movementFrom,
                    'movement_to' => $movementTo,
                ];
            }
        }

        return [
            'periods' => $periods,
            'period_count' => count($periods),
            'multiple' => count($periods) > 1,
            'recorded' => $recorded,
            'days' => $allocatedDays,
            'days_label' => $daysLabel,
            'service_days_label' => self::allocatedServiceDaysLabel($allocatedDays, $daysLabel),
        ];
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{from: string, to: string, days: int}>
     */
    private static function contiguousDateRanges(array $dates, string $timezone): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        if ($dates === []) {
            return [];
        }

        $ranges = [];
        $start = $dates[0];
        $previous = $dates[0];
        $days = 1;

        foreach (array_slice($dates, 1) as $date) {
            $expected = CarbonImmutable::parse($previous, $timezone)->addDay()->toDateString();

            if ($date === $expected) {
                $previous = $date;
                $days++;

                continue;
            }

            $ranges[] = [
                'from' => $start,
                'to' => $previous,
                'days' => $days,
            ];
            $start = $date;
            $previous = $date;
            $days = 1;
        }

        $ranges[] = [
            'from' => $start,
            'to' => $previous,
            'days' => $days,
        ];

        return $ranges;
    }

    private static function allocatedServiceDaysLabel(?int $days, string $daysLabel): string
    {
        if ($days === null) {
            return 'Not recorded';
        }

        if (str_contains(strtolower($daysLabel), 'future')) {
            return $daysLabel;
        }

        return $days === 1 ? '1 service day' : "{$days} service days";
    }

    private static function serviceDaysLabel(?int $days, bool $futureActualOnly, bool $hasFutureActual = false): string
    {
        if ($days === null) {
            return CrewMovementHistoryDuration::serviceDayLabel(null);
        }

        if ($futureActualOnly) {
            return CrewMovementHistoryDuration::futureActualLabel();
        }

        $label = CrewMovementHistoryDuration::serviceDayLabel($days);

        if ($hasFutureActual) {
            return $label.' + future actual';
        }

        return $label;
    }

    /**
     * @param  array{periods: list<array{status: string, end?: string|null}>}  $summary
     */
    private static function lastCompletedEnd(array $summary): ?string
    {
        $active = collect($summary['periods'])->firstWhere('status', CrewPhaseStatus::Active->value);

        if ($active !== null) {
            return null;
        }

        return collect($summary['periods'])
            ->where('status', CrewPhaseStatus::Completed->value)
            ->pluck('end')
            ->filter()
            ->last();
    }

    /**
     * @param  array{periods: list<array{status: string, end_at?: string|null}>}  $summary
     */
    private static function lastCompletedEndAt(array $summary): ?string
    {
        $active = collect($summary['periods'])->firstWhere('status', CrewPhaseStatus::Active->value);

        if ($active !== null) {
            return null;
        }

        return collect($summary['periods'])
            ->where('status', CrewPhaseStatus::Completed->value)
            ->pluck('end_at')
            ->filter()
            ->last();
    }

    /**
     * @param  array{periods: list<array<string, mixed>>}  $summary
     */
    private static function firstPeriodDateTime(array $summary, string $key): ?string
    {
        $value = $summary['periods'][0][$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @param  array<int, int>  $allocatedDaysByPhase
     * @param  array<int, true>  $futureOnlyPhaseIds
     * @return list<array<string, mixed>>
     */
    private static function phaseTimeline(
        Collection $phases,
        string $timezone,
        CarbonInterface $today,
        array $allocatedDaysByPhase = [],
        array $futureOnlyPhaseIds = [],
    ): array {
        $occurrenceByCode = [];

        return $phases
            ->sortBy('sequence')
            ->values()
            ->map(function (CrewAssignmentPhase $phase) use ($timezone, $today, $allocatedDaysByPhase, $futureOnlyPhaseIds, &$occurrenceByCode): array {
                $code = $phase->phase_code->value;
                $occurrenceByCode[$code] = ($occurrenceByCode[$code] ?? 0) + 1;
                $occurrence = $occurrenceByCode[$code];

                $durationEnd = match ($phase->status) {
                    CrewPhaseStatus::Completed => $phase->actual_end_at,
                    CrewPhaseStatus::Active => $today,
                    default => null,
                };
                $calendarSpanDays = CrewMovementHistoryDuration::inclusiveCalendarDays(
                    $phase->actual_start_at,
                    $durationEnd,
                    $timezone,
                    $today,
                );
                $phaseIsFutureOnly = isset($futureOnlyPhaseIds[(int) $phase->id]);
                $calendarDuration = CrewPayableDateOwnership::isExcludedPhase($phase->phase_code);
                $days = $phaseIsFutureOnly
                    ? 0
                    : ($calendarDuration
                        ? $calendarSpanDays
                        : ($allocatedDaysByPhase[(int) $phase->id] ?? ($calendarSpanDays === null ? null : 0)));

                return [
                    'id' => $phase->id,
                    'sequence' => $phase->sequence,
                    'occurrence' => $occurrence,
                    'phase_code' => $code,
                    'phase_label' => $phase->phase_code->label(),
                    'status' => $phase->status->value,
                    'planned_start_at' => self::dateTime($phase->planned_start_at, $timezone),
                    'planned_end_at' => self::dateTime($phase->planned_end_at, $timezone),
                    'actual_start_at' => self::dateTime($phase->actual_start_at, $timezone),
                    'actual_end_at' => self::dateTime($phase->actual_end_at, $timezone),
                    'days' => $days,
                    'days_label' => $phaseIsFutureOnly || ($days === 0 && $calendarSpanDays === 0)
                        ? CrewMovementHistoryDuration::futureActualLabel()
                        : CrewMovementHistoryDuration::serviceDayLabel($days),
                    'calendar_span_days' => $calendarSpanDays,
                    'remarks' => $phase->remarks,
                    'details' => is_array($phase->details) ? $phase->details : null,
                    'is_legacy' => false,
                ];
            })
            ->all();
    }

    /**
     * Phases that can claim the same employee/date across the full assignment chain.
     *
     * Prefer a page-batched relation (allocationCompetingPhases) to avoid N+1 on
     * report pages. Fall back to immediately linked previous/next phases, then to
     * a company/employee-scoped eligible-phase query when needed for A→B→C chains.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return Collection<int, CrewAssignmentPhase>
     */
    private static function allocationPhases(
        CrewAssignment $assignment,
        Collection $phases,
        int $companyId,
        int $employeeId,
    ): Collection {
        if ($assignment->relationLoaded('allocationCompetingPhases')) {
            $batched = $assignment->getRelation('allocationCompetingPhases');

            return $phases
                ->merge(
                    $batched instanceof Collection
                        ? $batched->filter(
                            fn (CrewAssignmentPhase $phase): bool => (! isset($phase->company_id) || (int) $phase->company_id === $companyId)
                                && (int) $phase->crew_assignment_id !== (int) $assignment->id
                                && CrewPayableDateOwnership::isEligibleStatus($phase->status),
                        )
                        : collect(),
                )
                ->unique('id')
                ->values();
        }

        // Fallback for non-paginated callers: walk the full linked chain (A→B→C).
        $chainIds = array_values(array_filter(
            CrewAssignmentLinkChain::assignmentIds($companyId, $employeeId, (int) $assignment->id),
            static fn (int $id): bool => $id !== (int) $assignment->id,
        ));

        if ($chainIds === []) {
            return $phases->values();
        }

        $competing = CrewAssignmentPhase::query()
            ->select([
                'id',
                'company_id',
                'crew_assignment_id',
                'phase_code',
                'status',
                'actual_start_at',
                'actual_end_at',
            ])
            ->where('company_id', $companyId)
            ->whereIn('status', CrewPayableDateOwnership::eligibleStatusValues())
            ->whereNotNull('actual_start_at')
            ->whereIn('crew_assignment_id', $chainIds)
            ->whereHas('assignment', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })
            ->get();

        return $phases->merge($competing)->unique('id')->values();
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<array<string, mixed>>
     */
    private static function trainingHistory(Collection $phases, string $timezone, int $companyId): array
    {
        $occurrence = 0;

        return $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::Training)
            ->sortBy('sequence')
            ->values()
            ->map(function (CrewAssignmentPhase $phase) use ($timezone, $companyId, &$occurrence): array {
                $occurrence++;
                $details = is_array($phase->details) ? $phase->details : [];
                $provider = isset($details['provider']) ? (string) $details['provider'] : null;
                $course = isset($details['course']) ? (string) $details['course'] : null;
                $parts = array_values(array_filter([$provider, $course]));
                $employeeTraining = $phase->relationLoaded('employeeTraining')
                    ? $phase->employeeTraining
                    : null;

                if ($employeeTraining !== null && isset($employeeTraining->company_id) && (int) $employeeTraining->company_id !== $companyId) {
                    $employeeTraining = null;
                }

                return [
                    'occurrence' => $occurrence,
                    'sequence' => $phase->sequence,
                    'status' => $phase->status->value,
                    'provider' => $provider !== '' ? $provider : null,
                    'course' => $course !== '' ? $course : null,
                    'summary' => $parts === [] ? null : implode(' — ', $parts),
                    'planned_start_at' => self::dateTime($phase->planned_start_at, $timezone),
                    'planned_end_at' => self::dateTime($phase->planned_end_at, $timezone),
                    'actual_start_at' => self::dateTime($phase->actual_start_at, $timezone),
                    'actual_end_at' => self::dateTime($phase->actual_end_at, $timezone),
                    'remarks' => $phase->remarks,
                    'employee_training_linked' => $employeeTraining !== null,
                    'employee_training' => $employeeTraining !== null ? [
                        'id' => $employeeTraining->id,
                        'course_id' => $employeeTraining->course_id,
                        'course_name' => $employeeTraining->relationLoaded('course')
                            ? $employeeTraining->course?->name
                            : null,
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function accommodationHistory(CrewAssignment $assignment, string $timezone): array
    {
        $stays = (new CrewAccommodationService)->assignmentAccommodationSummary($assignment, $timezone);

        if (! $assignment->relationLoaded('accommodationStays')) {
            return $stays;
        }

        $companyId = (int) $assignment->company_id;
        $phaseById = $assignment->accommodationStays
            ->filter(fn ($stay) => ! isset($stay->company_id) || (int) $stay->company_id === $companyId)
            ->keyBy('id')
            ->map(function ($stay) use ($companyId) {
                $phase = $stay->startedFromPhase;
                if ($phase !== null && isset($phase->company_id) && (int) $phase->company_id !== $companyId) {
                    return null;
                }

                return $phase;
            });

        return collect($stays)
            ->map(function (array $stay) use ($phaseById): array {
                $phase = $phaseById->get($stay['id'] ?? null);

                return [
                    ...$stay,
                    'started_from_phase_code' => $phase?->phase_code?->value,
                    'started_from_phase_label' => $phase?->phase_code?->label(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     previous: array<string, mixed>|null,
     *     next: list<array<string, mixed>>,
     *     relationship: string|null,
     *     relationship_label: string|null
     * }
     */
    private static function linkedAssignments(CrewAssignment $assignment, string $timezone): array
    {
        $companyId = (int) $assignment->company_id;
        $employeeId = (int) $assignment->employee_id;
        $previous = null;

        if ($assignment->relationLoaded('previousAssignment') && $assignment->previousAssignment !== null) {
            $prev = $assignment->previousAssignment;

            if ((int) $prev->company_id === $companyId && (int) $prev->employee_id === $employeeId) {
                $previous = self::linkedAssignmentSummary($prev, $timezone, 'previous');
            }
        }

        $next = [];

        if ($assignment->relationLoaded('nextAssignments')) {
            $next = $assignment->nextAssignments
                ->filter(fn (CrewAssignment $linked): bool => (int) $linked->company_id === $companyId && (int) $linked->employee_id === $employeeId)
                ->sortBy('id')
                ->values()
                ->map(fn (CrewAssignment $linked): array => self::linkedAssignmentSummary(
                    $linked,
                    $timezone,
                    'next',
                ))
                ->all();
        }

        $relationship = null;
        $relationshipLabel = null;

        if ($assignment->source === 'vessel_transfer' || $assignment->source === 'redeployment') {
            if ($previous !== null) {
                $relationship = $assignment->source;
                $relationshipLabel = self::sourceLabel($assignment->source);
            }
        } elseif ($next !== []) {
            $firstNext = $next[0];
            $relationship = $firstNext['source'] ?? null;
            $relationshipLabel = $firstNext['source_label'] ?? null;
        }

        return [
            'previous' => $previous,
            'next' => $next,
            'relationship' => $relationship,
            'relationship_label' => $relationshipLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkedAssignmentSummary(
        CrewAssignment $assignment,
        string $timezone,
        string $direction,
    ): array {
        $companyId = (int) $assignment->company_id;
        $phases = ($assignment->relationLoaded('phases') ? $assignment->phases : collect())
            ->filter(fn ($phase) => ! isset($phase->company_id) || (int) $phase->company_id === $companyId);

        $starting = self::startingCheckpoint(
            $phases,
            $timezone,
        );

        $currentPhase = ($assignment->relationLoaded('currentPhase') && $assignment->currentPhase)
            ? ((! isset($assignment->currentPhase->company_id) || (int) $assignment->currentPhase->company_id === $companyId)
                ? $assignment->currentPhase
                : null)
            : null;

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'direction' => $direction,
            'source' => $assignment->source,
            'source_label' => self::sourceLabel($assignment->source),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'vessel' => self::option($assignment->relationLoaded('vessel') ? $assignment->vessel : null, $companyId),
            'position' => CrewAssignmentPositionPresenter::option($assignment, $companyId),
            'client' => self::option($assignment->relationLoaded('client') ? $assignment->client : null, $companyId),
            'started_at' => self::dateTime($assignment->started_at, $timezone),
            'closed_at' => self::dateTime($assignment->closed_at, $timezone),
            'starting_phase_code' => $starting['code'],
            'starting_phase_label' => $starting['label'],
            'starting_phase_started_at' => $starting['started_at'],
            'current_phase_code' => $currentPhase?->phase_code?->value,
            'current_phase_label' => $currentPhase?->phase_code?->label(),
        ];
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{code: string|null, label: string|null, started_at: string|null}
     */
    private static function startingCheckpoint(Collection $phases, string $timezone): array
    {
        $first = $phases->sortBy('sequence')->first();

        if ($first === null) {
            return [
                'code' => null,
                'label' => null,
                'started_at' => null,
            ];
        }

        return [
            'code' => $first->phase_code->value,
            'label' => $first->phase_code->label(),
            'started_at' => self::dateTime($first->actual_start_at, $timezone),
        ];
    }

    private static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'crew_planning' => 'Crew Planning',
            'manual' => 'Manual',
            'vessel_transfer' => 'Vessel Transfer',
            'redeployment' => 'Redeployment',
            'historical_import' => 'Historical Import',
            'historical_manual' => 'Historical Manual',
            default => str($source ?? 'unknown')->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private static function option(?object $model, ?int $companyId = null): ?array
    {
        if ($model === null) {
            return null;
        }

        if ($companyId !== null && isset($model->company_id) && (int) $model->company_id !== $companyId) {
            return null;
        }

        $name = (string) ($model->name ?? $model->title ?? '');

        return ['id' => (int) $model->id, 'name' => $name];
    }

    private static function date(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->toDateString();
    }

    private static function dateTime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->format('Y-m-d H:i:s');
    }
}
