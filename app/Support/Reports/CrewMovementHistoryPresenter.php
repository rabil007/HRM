<?php

namespace App\Support\Reports;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
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
        $warnings = CrewMovementAttentionQuery::forAssignment($assignment);
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
            'visa_type' => self::option($assignment->companyVisaType),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'current_phase' => $assignment->currentPhase ? [
                'code' => $assignment->currentPhase->phase_code->value,
                'label' => $assignment->currentPhase->phase_code->label(),
                'status' => $assignment->currentPhase->status->value,
            ] : null,
            'source' => $assignment->source,
            'source_label' => match ($assignment->source) {
                'crew_planning' => 'Crew Planning',
                'manual' => 'Manual',
                default => str($assignment->source ?? 'unknown')->replace('_', ' ')->title()->toString(),
            },
            'planned_travel_in' => self::firstPlannedStart($phases, CrewPhaseCode::TravelIn, $timezone),
            'planned_join' => self::date($assignment->planned_join_at, $timezone),
            'planned_signoff' => self::date($assignment->planned_signoff_at, $timezone),
            'planned_travel_home' => self::date($assignment->planned_travel_at, $timezone),
            'phases' => $summaries,
            'pre_mobilisation' => self::flatten($summaries[CrewPhaseCode::PreMobilisation->value]),
            'travel_in' => self::flatten($summaries[CrewPhaseCode::TravelIn->value]),
            'join_standby' => $summaries[CrewPhaseCode::JoinStandby->value],
            'training' => [
                ...$training,
                'details' => self::trainingDetails($phases),
            ],
            'ready_to_join' => self::flatten($summaries[CrewPhaseCode::ReadyToJoin->value]),
            'on_vessel' => [
                ...$onVessel,
                'actual_join' => $onVessel['periods'][0]['start'] ?? null,
                'actual_disembarkation' => self::lastCompletedEnd($onVessel),
                'to' => self::lastCompletedEnd($onVessel),
            ],
            'demob_standby' => self::flatten($summaries[CrewPhaseCode::DemobStandby->value]),
            'home_redeploy' => self::flatten($summaries[CrewPhaseCode::HomeRedeploy->value]),
            'assignment_started' => self::date($assignment->started_at, $timezone),
            'assignment_closed' => self::date($assignment->closed_at, $timezone),
            'total_assignment_days' => $assignmentDays,
            'total_assignment_days_label' => CrewMovementHistoryDuration::label($assignmentDays),
            'remarks' => $assignment->remarks,
            'needs_attention' => $warnings !== [],
            'warnings' => collect($warnings)->pluck('label')->values()->all(),
            'company_timezone' => $timezone,
        ];
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{periods: list<array{sequence: int, start: string|null, end: string|null, status: string, days: int|null, days_label: string}>, total_days: int|null, total_days_label: string}
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
                    'status' => $phase->status->value,
                    'days' => $days,
                    'days_label' => CrewMovementHistoryDuration::label($days),
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
     * @param  array{periods: list<array{sequence: int, start: string|null, end: string|null, status: string, days: int|null, days_label: string}>, total_days: int|null, total_days_label: string}  $summary
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
     * @param  array{periods: list<array{sequence: int, start: string|null, end: string|null, status: string, days: int|null, days_label: string}>}  $summary
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
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<string>
     */
    private static function trainingDetails(Collection $phases): array
    {
        return $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::Training)
            ->sortBy('sequence')
            ->map(function (CrewAssignmentPhase $phase): ?string {
                $provider = is_array($phase->details) ? ($phase->details['provider'] ?? null) : null;
                $course = is_array($phase->details) ? ($phase->details['course'] ?? null) : null;
                $parts = array_values(array_filter([$provider, $course]));

                return $parts === [] ? null : implode(' — ', $parts);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     */
    private static function firstPlannedStart(
        Collection $phases,
        CrewPhaseCode $code,
        string $timezone,
    ): ?string {
        $phase = $phases
            ->first(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === $code);

        return self::date($phase?->planned_start_at, $timezone);
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
}
