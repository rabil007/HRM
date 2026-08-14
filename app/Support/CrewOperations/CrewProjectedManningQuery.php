<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewProjectedManningEventType;
use App\Enums\CrewProjectedManningStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\VesselManning;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Read-only date-aware projected vessel/rank manning for a company date range.
 *
 * Calendar-day semantics in the company timezone. Event display order on a shared
 * date is join before sign-off; min/max/gap/overlap are computed from the net
 * end-of-day count after all events for that date are applied.
 */
final class CrewProjectedManningQuery
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     company_timezone: string,
     *     summary: array{
     *         positions: int,
     *         current_gap_positions: int,
     *         future_gap_positions: int,
     *         covered_positions: int,
     *         overlap_positions: int,
     *         total_projected_shortfall_days: int
     *     },
     *     items: list<array{
     *         vessel_id: int,
     *         vessel_name: string,
     *         rank_id: int,
     *         rank_name: string,
     *         required_count: int,
     *         actual_onboard_at_start: int,
     *         projected_count_at_start: int,
     *         starting_count: int,
     *         minimum_projected_count: int,
     *         maximum_projected_count: int,
     *         current_gap: int,
     *         maximum_gap: int,
     *         next_gap_date: string|null,
     *         status: string,
     *         status_label: string,
     *         has_overlap: bool,
     *         has_open_ended_onboard: bool,
     *         events: list<array{
     *             date: string,
     *             type: string,
     *             delta: int,
     *             employee_id: int|null,
     *             crew_assignment_id: int|null,
     *             crew_planning_assignment_id: int|null,
     *             is_relief: bool
     *         }>,
     *         periods: list<array{
     *             from: string,
     *             to: string,
     *             projected_count: int,
     *             gap: int,
     *             excess: int
     *         }>
     *     }>
     * }
     */
    public function forCompany(
        int $companyId,
        string|CarbonInterface $from,
        string|CarbonInterface $to,
        ?int $vesselId = null,
        ?int $rankId = null,
    ): array {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        [$fromDate, $toDate] = $this->normalizeRange($from, $to, $timezone);

        $manningRows = VesselManning::query()
            ->where('company_id', $companyId)
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn ($q) => $q->where('rank_id', $rankId))
            ->with(['vessel:id,name', 'rank:id,name'])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->get();

        if ($manningRows->isEmpty()) {
            return $this->emptyResult($fromDate, $toDate, $timezone);
        }

        /** @var list<int> $vesselIds */
        $vesselIds = $manningRows->pluck('vessel_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        /** @var list<int> $rankIds */
        $rankIds = $manningRows->pluck('rank_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $assignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('vessel_id', $vesselIds)
            ->whereIn('rank_id', $rankIds)
            ->where('status', '!=', CrewAssignmentStatus::Cancelled->value)
            ->where(function ($query) use ($fromDate, $companyId): void {
                $query->whereIn('status', [
                    CrewAssignmentStatus::Draft->value,
                    CrewAssignmentStatus::Active->value,
                ])->orWhere(function ($completed) use ($fromDate, $companyId): void {
                    $completed->where('status', CrewAssignmentStatus::Completed->value)
                        ->whereHas('phases', function ($phase) use ($fromDate, $companyId): void {
                            $phase->where('company_id', $companyId)
                                ->where('phase_code', CrewPhaseCode::OnVessel->value)
                                ->whereNotNull('actual_start_at')
                                ->where(function ($interval) use ($fromDate): void {
                                    $interval->whereNull('actual_end_at')
                                        ->orWhere('actual_end_at', '>=', $fromDate.' 00:00:00');
                                });
                        });
                });
            })
            ->with([
                'phases' => fn ($q) => $q
                    ->where('company_id', $companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->orderBy('sequence'),
                'currentPhase' => fn ($q) => $q->where('company_id', $companyId),
                'planningAssignment' => fn ($q) => $q->where('company_id', $companyId),
                'employee' => fn ($q) => $q
                    ->where('company_id', $companyId)
                    ->select(['id', 'company_id', 'name', 'employee_no', 'status']),
            ])
            ->get([
                'id',
                'company_id',
                'employee_id',
                'vessel_id',
                'rank_id',
                'status',
                'current_phase_id',
                'planned_join_at',
                'planned_signoff_at',
            ]);

        $linkedAssignmentIds = $assignments->pluck('id')->all();

        $planningOnly = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('vessel_id', $vesselIds)
            ->whereIn('rank_id', $rankIds)
            ->whereNull('crew_assignment_id')
            ->whereNotNull('employee_id')
            ->with([
                'employee' => fn ($q) => $q
                    ->where('company_id', $companyId)
                    ->select(['id', 'company_id', 'name', 'employee_no', 'status']),
            ])
            ->get([
                'id',
                'company_id',
                'employee_id',
                'vessel_id',
                'rank_id',
                'planned_join_date',
                'planned_leave_date',
                'relieves_crew_assignment_id',
                'crew_assignment_id',
            ]);

        $segmentsByKey = $this->buildSegments(
            $assignments,
            $planningOnly,
            $companyId,
            $timezone,
            $linkedAssignmentIds,
        );

        $items = [];
        $summary = [
            'positions' => 0,
            'current_gap_positions' => 0,
            'future_gap_positions' => 0,
            'covered_positions' => 0,
            'overlap_positions' => 0,
            'total_projected_shortfall_days' => 0,
        ];

        foreach ($manningRows as $row) {
            $key = $this->key((int) $row->vessel_id, (int) $row->rank_id);
            $item = $this->projectPosition(
                vesselId: (int) $row->vessel_id,
                vesselName: (string) ($row->vessel?->name ?? ''),
                rankId: (int) $row->rank_id,
                rankName: (string) ($row->rank?->name ?? ''),
                required: (int) $row->required_count,
                fromDate: $fromDate,
                toDate: $toDate,
                segments: $segmentsByKey->get($key, collect()),
            );

            $items[] = $item;
            $summary['positions']++;
            $summary['total_projected_shortfall_days'] += $this->shortfallDays($item['periods'], $fromDate, $toDate);

            if ($item['has_overlap']) {
                $summary['overlap_positions']++;
            }

            match ($item['status']) {
                CrewProjectedManningStatus::CurrentGap->value => $summary['current_gap_positions']++,
                CrewProjectedManningStatus::FutureGap->value => $summary['future_gap_positions']++,
                CrewProjectedManningStatus::Overlap->value => null,
                default => $summary['covered_positions']++,
            };
        }

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'company_timezone' => $timezone,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @param  Collection<int, CrewAssignment>  $assignments
     * @param  Collection<int, CrewPlanningAssignment>  $planningOnly
     * @param  list<int>  $linkedAssignmentIds
     * @return Collection<string, Collection<int, array{
     *     join: string,
     *     actual_end: string|null,
     *     projected_leave: string|null,
     *     is_actual: bool,
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }>>
     */
    private function buildSegments(
        Collection $assignments,
        Collection $planningOnly,
        int $companyId,
        string $timezone,
        array $linkedAssignmentIds,
    ): Collection {
        $byKey = collect();

        foreach ($assignments as $assignment) {
            foreach ($this->segmentsFromAssignment($assignment, $companyId, $timezone) as $segment) {
                $key = $this->key((int) $assignment->vessel_id, (int) $assignment->rank_id);

                if (! $byKey->has($key)) {
                    $byKey->put($key, collect());
                }

                $byKey->get($key)->push($segment);
            }
        }

        foreach ($planningOnly as $planning) {
            if ((int) $planning->company_id !== $companyId) {
                continue;
            }

            if ($planning->crew_assignment_id !== null
                && in_array((int) $planning->crew_assignment_id, $linkedAssignmentIds, true)) {
                continue;
            }

            if ($planning->employee_id === null || $planning->planned_join_date === null) {
                continue;
            }

            $employee = $planning->relationLoaded('employee') ? $planning->employee : null;

            if ($employee === null || (int) $employee->company_id !== $companyId) {
                continue;
            }

            if ($employee->status !== 'active') {
                continue;
            }

            $key = $this->key((int) $planning->vessel_id, (int) $planning->rank_id);

            if (! $byKey->has($key)) {
                $byKey->put($key, collect());
            }

            $byKey->get($key)->push([
                'join' => $planning->planned_join_date->toDateString(),
                'actual_end' => null,
                'projected_leave' => $planning->planned_leave_date?->toDateString(),
                'is_actual' => false,
                'employee_id' => (int) $employee->id,
                'crew_assignment_id' => null,
                'crew_planning_assignment_id' => (int) $planning->id,
                'is_relief' => $planning->relieves_crew_assignment_id !== null,
            ]);
        }

        return $byKey;
    }

    /**
     * @return list<array{
     *     join: string,
     *     actual_end: string|null,
     *     projected_leave: string|null,
     *     is_actual: bool,
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }>
     */
    private function segmentsFromAssignment(
        CrewAssignment $assignment,
        int $companyId,
        string $timezone,
    ): array {
        if ($assignment->status === CrewAssignmentStatus::Cancelled) {
            return [];
        }

        if ((int) $assignment->company_id !== $companyId) {
            return [];
        }

        if ($assignment->employee_id !== null) {
            $employee = $assignment->relationLoaded('employee') ? $assignment->employee : null;

            if ($employee === null || (int) $employee->company_id !== $companyId) {
                return [];
            }

            if (
                in_array($assignment->status, [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active], true)
                && $employee->status !== 'active'
            ) {
                return [];
            }

            $employeeId = (int) $employee->id;
        } else {
            $employeeId = null;
        }

        $planning = $assignment->relationLoaded('planningAssignment')
            ? $assignment->planningAssignment
            : null;

        if ($planning !== null && (int) $planning->company_id !== $companyId) {
            $planning = null;
        }

        $phases = ($assignment->relationLoaded('phases') ? $assignment->phases : collect())
            ->filter(function (CrewAssignmentPhase $phase) use ($companyId): bool {
                return $phase->phase_code === CrewPhaseCode::OnVessel
                    && (int) $phase->company_id === $companyId;
            })
            ->sortBy(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->values();

        $segments = [];
        $hasActualP4 = false;

        foreach ($phases as $phase) {
            if ($phase->actual_start_at === null) {
                continue;
            }

            $hasActualP4 = true;
            $join = $this->toCompanyDate($phase->actual_start_at, $timezone);
            $actualEnd = $phase->actual_end_at !== null
                ? $this->toCompanyDate($phase->actual_end_at, $timezone)
                : null;
            $projectedLeave = $actualEnd
                ?? $this->resolveForecastLeave($assignment, $phase, $planning, $timezone);

            $segments[] = [
                'join' => $join,
                'actual_end' => $actualEnd,
                'projected_leave' => $projectedLeave,
                'is_actual' => true,
                'employee_id' => $employeeId,
                'crew_assignment_id' => (int) $assignment->id,
                'crew_planning_assignment_id' => $planning?->id !== null ? (int) $planning->id : null,
                'is_relief' => $planning?->relieves_crew_assignment_id !== null,
            ];
        }

        if ($hasActualP4 || ! $this->allowsProjectedFallback($assignment)) {
            return $segments;
        }

        $join = $this->resolveForecastJoin($assignment, $planning, $timezone);

        if ($join === null) {
            return $segments;
        }

        $segments[] = [
            'join' => $join,
            'actual_end' => null,
            'projected_leave' => $this->resolveForecastLeave($assignment, null, $planning, $timezone),
            'is_actual' => false,
            'employee_id' => $employeeId,
            'crew_assignment_id' => (int) $assignment->id,
            'crew_planning_assignment_id' => $planning?->id !== null ? (int) $planning->id : null,
            'is_relief' => $planning?->relieves_crew_assignment_id !== null,
        ];

        return $segments;
    }

    private function allowsProjectedFallback(CrewAssignment $assignment): bool
    {
        if ($assignment->status === CrewAssignmentStatus::Completed) {
            return false;
        }

        if (! in_array($assignment->status, [
            CrewAssignmentStatus::Draft,
            CrewAssignmentStatus::Active,
        ], true)) {
            return false;
        }

        $current = $assignment->relationLoaded('currentPhase')
            ? $assignment->currentPhase
            : null;

        if ($current !== null && in_array($current->phase_code, [
            CrewPhaseCode::DemobStandby,
            CrewPhaseCode::HomeRedeploy,
        ], true)) {
            return false;
        }

        return true;
    }

    private function resolveForecastJoin(
        CrewAssignment $assignment,
        ?CrewPlanningAssignment $planning,
        string $timezone,
    ): ?string {
        if ($assignment->planned_join_at !== null) {
            return $this->toCompanyDate($assignment->planned_join_at, $timezone);
        }

        return $planning?->planned_join_date?->toDateString();
    }

    private function resolveForecastLeave(
        CrewAssignment $assignment,
        ?CrewAssignmentPhase $phase,
        ?CrewPlanningAssignment $planning,
        string $timezone,
    ): ?string {
        if ($assignment->planned_signoff_at !== null) {
            return $this->toCompanyDate($assignment->planned_signoff_at, $timezone);
        }

        if ($phase?->planned_end_at !== null) {
            return $this->toCompanyDate($phase->planned_end_at, $timezone);
        }

        return $planning?->planned_leave_date?->toDateString();
    }

    /**
     * @param  Collection<int, array{
     *     join: string,
     *     actual_end: string|null,
     *     projected_leave: string|null,
     *     is_actual: bool,
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }>  $segments
     * @return array<string, mixed>
     */
    private function projectPosition(
        int $vesselId,
        string $vesselName,
        int $rankId,
        string $rankName,
        int $required,
        string $fromDate,
        string $toDate,
        Collection $segments,
    ): array {
        $actualOnboard = 0;
        $projectedAtStart = 0;
        $events = [];
        $hasOpenEnded = false;

        foreach ($segments as $segment) {
            $join = $segment['join'];
            $actualEnd = $segment['actual_end'];
            $projectedLeave = $segment['projected_leave'];

            // Operational truth: forecast leave never clears actual onboard.
            if ($segment['is_actual']
                && $join <= $fromDate
                && ($actualEnd === null || $actualEnd >= $fromDate)) {
                $actualOnboard++;
            }

            $projectedCoversStart = $join <= $fromDate
                && ($projectedLeave === null || $projectedLeave >= $fromDate);

            if ($projectedCoversStart) {
                $projectedAtStart++;

                if ($projectedLeave === null) {
                    $hasOpenEnded = true;
                } elseif ($projectedLeave >= $fromDate && $projectedLeave <= $toDate) {
                    $events[] = $this->event(
                        $projectedLeave,
                        CrewProjectedManningEventType::SignOff,
                        $segment,
                    );
                }

                continue;
            }

            // Forecast already ended before the range — no projected events in range.
            if ($join <= $fromDate && $projectedLeave !== null && $projectedLeave < $fromDate) {
                continue;
            }

            if ($join > $toDate) {
                continue;
            }

            if ($join > $fromDate && $join <= $toDate) {
                $events[] = $this->event(
                    $join,
                    CrewProjectedManningEventType::Join,
                    $segment,
                );

                if ($projectedLeave === null) {
                    $hasOpenEnded = true;
                } elseif ($projectedLeave >= $fromDate && $projectedLeave <= $toDate) {
                    $events[] = $this->event(
                        $projectedLeave,
                        CrewProjectedManningEventType::SignOff,
                        $segment,
                    );
                }
            }
        }

        usort($events, function (array $a, array $b): int {
            $dateCmp = strcmp($a['date'], $b['date']);

            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return CrewProjectedManningEventType::from($a['type'])->sortOrder()
                <=> CrewProjectedManningEventType::from($b['type'])->sortOrder();
        });

        $count = $projectedAtStart;
        $minimum = $projectedAtStart;
        $maximum = $projectedAtStart;
        $maximumGap = max(0, $required - $projectedAtStart);
        $nextGapDate = $projectedAtStart < $required ? $fromDate : null;
        $hasDeparture = false;
        $hasIncoming = false;

        $periods = [];
        $periodStart = $fromDate;
        $periodCount = $projectedAtStart;

        $flushPeriod = function (string $periodEnd) use (&$periods, &$periodStart, &$periodCount, $required): void {
            if ($periodStart > $periodEnd) {
                return;
            }

            $periods[] = [
                'from' => $periodStart,
                'to' => $periodEnd,
                'projected_count' => $periodCount,
                'gap' => max(0, $required - $periodCount),
                'excess' => max(0, $periodCount - $required),
            ];
        };

        foreach (collect($events)->groupBy('date') as $date => $dayEvents) {
            $day = (string) $date;

            if ($day > $periodStart) {
                $flushPeriod(CarbonImmutable::parse($day)->subDay()->toDateString());
                $periodStart = $day;
            }

            foreach ($dayEvents as $event) {
                $count += (int) $event['delta'];

                if ($event['type'] === CrewProjectedManningEventType::SignOff->value) {
                    $hasDeparture = true;
                }

                if ($event['type'] === CrewProjectedManningEventType::Join->value) {
                    $hasIncoming = true;
                }
            }

            // Aggregate after all same-day events so one-for-one handovers do not false-overlap.
            $minimum = min($minimum, $count);
            $maximum = max($maximum, $count);
            $gap = max(0, $required - $count);
            $maximumGap = max($maximumGap, $gap);

            if ($gap > 0 && $nextGapDate === null) {
                $nextGapDate = $day;
            }

            $periodCount = $count;
        }

        $flushPeriod($toDate);

        $currentGap = max(0, $required - $projectedAtStart);
        $hasOverlap = $maximum > $required;
        $status = $this->resolveStatus(
            currentGap: $currentGap,
            maximumGap: $maximumGap,
            hasDeparture: $hasDeparture,
            hasIncoming: $hasIncoming,
            hasOverlap: $hasOverlap,
        );

        return [
            'vessel_id' => $vesselId,
            'vessel_name' => $vesselName,
            'rank_id' => $rankId,
            'rank_name' => $rankName,
            'required_count' => $required,
            'actual_onboard_at_start' => $actualOnboard,
            'projected_count_at_start' => $projectedAtStart,
            'starting_count' => $projectedAtStart,
            'minimum_projected_count' => $minimum,
            'maximum_projected_count' => $maximum,
            'current_gap' => $currentGap,
            'maximum_gap' => $maximumGap,
            'next_gap_date' => $maximumGap > 0 ? $nextGapDate : null,
            'status' => $status->value,
            'status_label' => $status->label(),
            'has_overlap' => $hasOverlap,
            'has_open_ended_onboard' => $hasOpenEnded,
            'events' => array_values($events),
            'periods' => $periods,
        ];
    }

    /**
     * @param  array{
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }  $segment
     * @return array{
     *     date: string,
     *     type: string,
     *     delta: int,
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }
     */
    private function event(string $date, CrewProjectedManningEventType $type, array $segment): array
    {
        return [
            'date' => $date,
            'type' => $type->value,
            'delta' => $type === CrewProjectedManningEventType::Join ? 1 : -1,
            'employee_id' => $segment['employee_id'],
            'crew_assignment_id' => $segment['crew_assignment_id'],
            'crew_planning_assignment_id' => $segment['crew_planning_assignment_id'],
            'is_relief' => $segment['is_relief'],
        ];
    }

    private function resolveStatus(
        int $currentGap,
        int $maximumGap,
        bool $hasDeparture,
        bool $hasIncoming,
        bool $hasOverlap,
    ): CrewProjectedManningStatus {
        if ($currentGap > 0) {
            return CrewProjectedManningStatus::CurrentGap;
        }

        if ($maximumGap > 0) {
            return CrewProjectedManningStatus::FutureGap;
        }

        if ($hasDeparture && $hasIncoming) {
            return CrewProjectedManningStatus::CoveredByIncoming;
        }

        if ($hasOverlap) {
            return CrewProjectedManningStatus::Overlap;
        }

        return CrewProjectedManningStatus::Covered;
    }

    /**
     * @param  list<array{from: string, to: string, projected_count: int, gap: int, excess: int}>  $periods
     */
    private function shortfallDays(array $periods, string $fromDate, string $toDate): int
    {
        $days = 0;

        foreach ($periods as $period) {
            if ($period['gap'] <= 0) {
                continue;
            }

            $start = CarbonImmutable::parse(max($period['from'], $fromDate));
            $end = CarbonImmutable::parse(min($period['to'], $toDate));

            if ($end->lt($start)) {
                continue;
            }

            $days += ((int) $start->diffInDays($end) + 1) * (int) $period['gap'];
        }

        return $days;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeRange(
        string|CarbonInterface $from,
        string|CarbonInterface $to,
        string $timezone,
    ): array {
        $fromDate = $this->toCompanyDate($from, $timezone);
        $toDate = $this->toCompanyDate($to, $timezone);

        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('Projection "from" date must be on or before "to" date.');
        }

        return [$fromDate, $toDate];
    }

    private function toCompanyDate(string|CarbonInterface $value, string $timezone): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone($timezone)->toDateString();
        }

        return CarbonImmutable::parse((string) $value, $timezone)->toDateString();
    }

    private function key(int $vesselId, int $rankId): string
    {
        return $vesselId.'|'.$rankId;
    }

    /**
     * @return array{from: string, to: string, company_timezone: string, summary: array<string, int>, items: list<array<string, mixed>>}
     */
    private function emptyResult(string $fromDate, string $toDate, string $timezone): array
    {
        return [
            'from' => $fromDate,
            'to' => $toDate,
            'company_timezone' => $timezone,
            'summary' => [
                'positions' => 0,
                'current_gap_positions' => 0,
                'future_gap_positions' => 0,
                'covered_positions' => 0,
                'overlap_positions' => 0,
                'total_projected_shortfall_days' => 0,
            ],
            'items' => [],
        ];
    }
}
