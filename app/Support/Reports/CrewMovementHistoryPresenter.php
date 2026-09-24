<?php

namespace App\Support\Reports;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewAccommodation\CrewAccommodationService;
use App\Support\CrewMovements\CrewDateProvenance;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewTourProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewMovementHistoryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CrewAssignment $assignment): array
    {
        $timezone = (string) ($assignment->company?->timezone ?? config('app.timezone', 'UTC'));
        $today = now($timezone);
        $phases = $assignment->phases->sortBy('sequence')->values();
        $tour = (new CrewTourProgress)->forAssignment($assignment, null, $timezone);
        $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $tour);
        $summaries = [];

        foreach (CrewPhaseCode::cases() as $phaseCode) {
            $summaries[$phaseCode->value] = self::phaseSummary($phases, $phaseCode, $timezone, $today);
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
        $payrollDays = CrewMovementHistoryPayrollDays::summarize(
            $phases,
            $timezone,
            $today,
        );
        $approvedCorrections = $assignment->relationLoaded('corrections')
            ? $assignment->corrections->where('status', CrewMovementCorrectionStatus::Approved)
            : collect();
        $pendingCorrections = $assignment->relationLoaded('corrections')
            ? $assignment->corrections->where('status', CrewMovementCorrectionStatus::Pending)
            : collect();

        $plannedJoin = CrewDateProvenance::plannedJoin($assignment, $timezone);
        $plannedArrival = CrewDateProvenance::plannedArrival($assignment, $timezone);
        $actualArrival = CrewDateProvenance::actualArrival($assignment, $timezone);
        $plannedSignoff = CrewDateProvenance::plannedSignoff($assignment, $timezone);
        $plannedTravelHome = CrewDateProvenance::plannedTravel($assignment, $timezone);
        $plannedTravelInPhase = $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::TravelIn)
            ->sortBy('sequence')
            ->first();
        $plannedTravelIn = CrewDateProvenance::phasePlanned(
            $plannedTravelInPhase,
            $assignment,
            $timezone,
        );
        $travelIn = self::flatten($summaries[CrewPhaseCode::TravelIn->value]);
        $readyToJoin = self::flatten($summaries[CrewPhaseCode::ReadyToJoin->value]);
        $homeRedeploy = self::flatten($summaries[CrewPhaseCode::HomeRedeploy->value]);
        $trainingHistory = self::trainingHistory($phases, $timezone);
        $phaseTimeline = self::phaseTimeline($phases, $timezone, $today);
        $accommodation = self::accommodationHistory($assignment, $timezone);
        $linked = self::linkedAssignments($assignment, $timezone);

        $actualJoinAt = self::firstPeriodDateTime($onVessel, 'start_at');
        $actualDisembarkationAt = self::lastCompletedEndAt($onVessel);
        $actualReturnHomeAt = $homeRedeploy['periods'][0]['start_at'] ?? null;

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'employee' => [
                'id' => $assignment->employee?->id,
                'employee_no' => $assignment->employee?->employee_no,
                'name' => $assignment->employee?->name,
            ],
            'rank' => self::option($assignment->rank),
            'vessel' => self::option($assignment->vessel),
            'client' => self::option($assignment->client),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'current_phase' => $assignment->currentPhase ? [
                'code' => $assignment->currentPhase->phase_code->value,
                'label' => $assignment->currentPhase->phase_code->label(),
                'status' => $assignment->currentPhase->status->value,
            ] : null,
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
                self::actualArrivalTimestamp($assignment),
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
            'has_legacy_phases' => $travelIn['periods'] !== [] || $readyToJoin['periods'] !== [],
            'pre_mobilisation' => self::flatten($summaries[CrewPhaseCode::PreMobilisation->value]),
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
                'actual_return_home_at' => $actualReturnHomeAt,
            ],
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
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{periods: list<array<string, mixed>>, total_days: int|null, total_days_label: string}
     */
    public static function phaseSummary(
        Collection $phases,
        CrewPhaseCode $code,
        string $timezone,
        ?CarbonInterface $today = null,
    ): array {
        $today ??= now($timezone);
        $totalDays = 0;
        $hasDuration = false;

        $periods = $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === $code)
            ->sortBy('sequence')
            ->map(function (CrewAssignmentPhase $phase) use ($timezone, $today, &$totalDays, &$hasDuration): array {
                $durationEnd = match ($phase->status) {
                    CrewPhaseStatus::Completed => $phase->actual_end_at,
                    CrewPhaseStatus::Active => $today,
                    default => null,
                };
                $days = CrewMovementHistoryDuration::elapsedDays(
                    $phase->actual_start_at,
                    $durationEnd,
                    $timezone,
                );

                if ($days !== null) {
                    $totalDays += $days;
                    $hasDuration = true;
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
                    'days' => $days,
                    'days_label' => CrewMovementHistoryDuration::label($days),
                    'remarks' => $phase->remarks,
                ];
            })
            ->values()
            ->all();

        return [
            'periods' => $periods,
            'total_days' => $hasDuration ? $totalDays : null,
            'total_days_label' => CrewMovementHistoryDuration::label($hasDuration ? $totalDays : null),
        ];
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
     * @return list<array<string, mixed>>
     */
    private static function phaseTimeline(
        Collection $phases,
        string $timezone,
        CarbonInterface $today,
    ): array {
        $occurrenceByCode = [];

        return $phases
            ->sortBy('sequence')
            ->values()
            ->map(function (CrewAssignmentPhase $phase) use ($timezone, $today, &$occurrenceByCode): array {
                $code = $phase->phase_code->value;
                $occurrenceByCode[$code] = ($occurrenceByCode[$code] ?? 0) + 1;
                $occurrence = $occurrenceByCode[$code];

                $durationEnd = match ($phase->status) {
                    CrewPhaseStatus::Completed => $phase->actual_end_at,
                    CrewPhaseStatus::Active => $today,
                    default => null,
                };
                $days = CrewMovementHistoryDuration::elapsedDays(
                    $phase->actual_start_at,
                    $durationEnd,
                    $timezone,
                );

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
                    'days_label' => CrewMovementHistoryDuration::label($days),
                    'remarks' => $phase->remarks,
                    'details' => is_array($phase->details) ? $phase->details : null,
                    'is_legacy' => in_array($phase->phase_code, [
                        CrewPhaseCode::TravelIn,
                        CrewPhaseCode::ReadyToJoin,
                    ], true),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<array<string, mixed>>
     */
    private static function trainingHistory(Collection $phases, string $timezone): array
    {
        $occurrence = 0;

        return $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::Training)
            ->sortBy('sequence')
            ->values()
            ->map(function (CrewAssignmentPhase $phase) use ($timezone, &$occurrence): array {
                $occurrence++;
                $details = is_array($phase->details) ? $phase->details : [];
                $provider = isset($details['provider']) ? (string) $details['provider'] : null;
                $course = isset($details['course']) ? (string) $details['course'] : null;
                $parts = array_values(array_filter([$provider, $course]));
                $employeeTraining = $phase->relationLoaded('employeeTraining')
                    ? $phase->employeeTraining
                    : null;

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

        $phaseById = $assignment->accommodationStays
            ->keyBy('id')
            ->map(fn ($stay) => $stay->startedFromPhase);

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
        $previous = null;

        if ($assignment->relationLoaded('previousAssignment') && $assignment->previousAssignment !== null) {
            $prev = $assignment->previousAssignment;

            if ((int) $prev->company_id === (int) $assignment->company_id) {
                $previous = self::linkedAssignmentSummary($prev, $timezone, 'previous');
            }
        }

        $next = [];

        if ($assignment->relationLoaded('nextAssignments')) {
            $next = $assignment->nextAssignments
                ->filter(fn (CrewAssignment $linked): bool => (int) $linked->company_id === (int) $assignment->company_id)
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
            $relationship = $assignment->source;
            $relationshipLabel = self::sourceLabel($assignment->source);
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
        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'direction' => $direction,
            'source' => $assignment->source,
            'source_label' => self::sourceLabel($assignment->source),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'vessel' => self::option($assignment->relationLoaded('vessel') ? $assignment->vessel : null),
            'rank' => self::option($assignment->relationLoaded('rank') ? $assignment->rank : null),
            'client' => self::option($assignment->relationLoaded('client') ? $assignment->client : null),
            'started_at' => self::dateTime($assignment->started_at, $timezone),
            'closed_at' => self::dateTime($assignment->closed_at, $timezone),
            'current_phase_code' => $assignment->relationLoaded('currentPhase') && $assignment->currentPhase
                ? $assignment->currentPhase->phase_code->value
                : null,
        ];
    }

    private static function actualArrivalTimestamp(CrewAssignment $assignment): ?CarbonInterface
    {
        if (! $assignment->relationLoaded('phases')) {
            return null;
        }

        $joinStandby = $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::JoinStandby)
            ->sortBy('sequence')
            ->first();

        if ($joinStandby?->actual_start_at !== null) {
            return $joinStandby->actual_start_at;
        }

        $travelIn = $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::TravelIn
                && $phase->status === CrewPhaseStatus::Completed)
            ->sortBy('sequence')
            ->first();

        return $travelIn?->actual_end_at;
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
    private static function option(?object $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return ['id' => (int) $model->id, 'name' => (string) $model->name];
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
