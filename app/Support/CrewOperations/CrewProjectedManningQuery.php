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
 * Calendar-day semantics in the company timezone. Same-day: join events apply
 * before sign-off events (handover without an artificial one-day gap).
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
            ->with([
                'phases' => fn ($q) => $q->where('phase_code', CrewPhaseCode::OnVessel->value),
                'planningAssignment',
            ])
            ->get([
                'id',
                'company_id',
                'employee_id',
                'vessel_id',
                'rank_id',
                'status',
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

        $segmentsByKey = $this->buildSegments($assignments, $planningOnly, $timezone, $linkedAssignmentIds);

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

            match ($item['status']) {
                CrewProjectedManningStatus::CurrentGap->value => $summary['current_gap_positions']++,
                CrewProjectedManningStatus::FutureGap->value => $summary['future_gap_positions']++,
                CrewProjectedManningStatus::Overlap->value => $summary['overlap_positions']++,
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
     *     join: string|null,
     *     leave: string|null,
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }>>
     */
    private function buildSegments(
        Collection $assignments,
        Collection $planningOnly,
        string $timezone,
        array $linkedAssignmentIds,
    ): Collection {
        $byKey = collect();

        foreach ($assignments as $assignment) {
            $segment = $this->segmentFromAssignment($assignment, $timezone);

            if ($segment === null) {
                continue;
            }

            $key = $this->key((int) $assignment->vessel_id, (int) $assignment->rank_id);

            if (! $byKey->has($key)) {
                $byKey->put($key, collect());
            }

            $byKey->get($key)->push($segment);
        }

        foreach ($planningOnly as $planning) {
            if ($planning->crew_assignment_id !== null
                && in_array((int) $planning->crew_assignment_id, $linkedAssignmentIds, true)) {
                continue;
            }

            if ($planning->employee_id === null || $planning->planned_join_date === null) {
                continue;
            }

            $join = $planning->planned_join_date->toDateString();
            $leave = $planning->planned_leave_date?->toDateString();
            $key = $this->key((int) $planning->vessel_id, (int) $planning->rank_id);

            if (! $byKey->has($key)) {
                $byKey->put($key, collect());
            }

            $byKey->get($key)->push([
                'join' => $join,
                'leave' => $leave,
                'employee_id' => (int) $planning->employee_id,
                'crew_assignment_id' => null,
                'crew_planning_assignment_id' => (int) $planning->id,
                'is_relief' => $planning->relieves_crew_assignment_id !== null,
            ]);
        }

        return $byKey;
    }

    /**
     * @return array{
     *     join: string|null,
     *     leave: string|null,
     *     employee_id: int|null,
     *     crew_assignment_id: int|null,
     *     crew_planning_assignment_id: int|null,
     *     is_relief: bool
     * }|null
     */
    private function segmentFromAssignment(CrewAssignment $assignment, string $timezone): ?array
    {
        if ($assignment->status === CrewAssignmentStatus::Cancelled) {
            return null;
        }

        $p4 = $this->primaryOnVesselPhase($assignment);
        $planning = $assignment->relationLoaded('planningAssignment')
            ? $assignment->planningAssignment
            : null;

        $join = $this->resolveJoinDate($assignment, $p4, $planning, $timezone);
        $leave = $this->resolveLeaveDate($assignment, $p4, $planning, $timezone);

        if ($join === null && $leave === null && $p4?->actual_start_at === null) {
            return null;
        }

        if ($join === null) {
            return null;
        }

        return [
            'join' => $join,
            'leave' => $leave,
            'employee_id' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
            'crew_assignment_id' => (int) $assignment->id,
            'crew_planning_assignment_id' => $planning?->id !== null ? (int) $planning->id : null,
            'is_relief' => $planning?->relieves_crew_assignment_id !== null,
        ];
    }

    private function primaryOnVesselPhase(CrewAssignment $assignment): ?CrewAssignmentPhase
    {
        $phases = $assignment->relationLoaded('phases')
            ? $assignment->phases
            : $assignment->phases()->where('phase_code', CrewPhaseCode::OnVessel)->get();

        $onVessel = $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::OnVessel)
            ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->values();

        return $onVessel->first(fn (CrewAssignmentPhase $phase): bool => $phase->actual_start_at !== null)
            ?? $onVessel->first();
    }

    private function resolveJoinDate(
        CrewAssignment $assignment,
        ?CrewAssignmentPhase $p4,
        ?CrewPlanningAssignment $planning,
        string $timezone,
    ): ?string {
        if ($p4?->actual_start_at !== null) {
            return $p4->actual_start_at->copy()->timezone($timezone)->toDateString();
        }

        if ($assignment->planned_join_at !== null) {
            return $assignment->planned_join_at->copy()->timezone($timezone)->toDateString();
        }

        return $planning?->planned_join_date?->toDateString();
    }

    private function resolveLeaveDate(
        CrewAssignment $assignment,
        ?CrewAssignmentPhase $p4,
        ?CrewPlanningAssignment $planning,
        string $timezone,
    ): ?string {
        if ($p4?->actual_end_at !== null) {
            return $p4->actual_end_at->copy()->timezone($timezone)->toDateString();
        }

        if ($assignment->planned_signoff_at !== null) {
            return $assignment->planned_signoff_at->copy()->timezone($timezone)->toDateString();
        }

        if ($p4?->planned_end_at !== null) {
            return $p4->planned_end_at->copy()->timezone($timezone)->toDateString();
        }

        return $planning?->planned_leave_date?->toDateString();
    }

    /**
     * @param  Collection<int, array{
     *     join: string|null,
     *     leave: string|null,
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
        $starting = 0;
        $events = [];
        $hasOpenEnded = false;

        foreach ($segments as $segment) {
            $join = $segment['join'];
            $leave = $segment['leave'];

            if ($join === null) {
                continue;
            }

            $onboardAtStart = $join <= $fromDate && ($leave === null || $leave >= $fromDate);

            if ($onboardAtStart) {
                $starting++;

                if ($leave === null) {
                    $hasOpenEnded = true;
                } elseif ($leave >= $fromDate && $leave <= $toDate) {
                    $events[] = $this->event(
                        $leave,
                        CrewProjectedManningEventType::SignOff,
                        $segment,
                    );
                }

                continue;
            }

            if ($leave !== null && $leave < $fromDate) {
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

                if ($leave === null) {
                    $hasOpenEnded = true;
                } elseif ($leave >= $fromDate && $leave <= $toDate) {
                    $events[] = $this->event(
                        $leave,
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

            $typeA = CrewProjectedManningEventType::from($a['type']);
            $typeB = CrewProjectedManningEventType::from($b['type']);

            return $typeA->sortOrder() <=> $typeB->sortOrder();
        });

        $count = $starting;
        $minimum = $starting;
        $maximum = $starting;
        $maximumGap = max(0, $required - $starting);
        $nextGapDate = $starting < $required ? $fromDate : null;
        $hasDeparture = false;
        $hasIncoming = false;

        $periods = [];
        $periodStart = $fromDate;
        $periodCount = $starting;

        $flushPeriod = function (string $periodEnd) use (&$periods, &$periodStart, &$periodCount, $required): void {
            if ($periodStart > $periodEnd) {
                return;
            }

            $gap = max(0, $required - $periodCount);
            $excess = max(0, $periodCount - $required);
            $periods[] = [
                'from' => $periodStart,
                'to' => $periodEnd,
                'projected_count' => $periodCount,
                'gap' => $gap,
                'excess' => $excess,
            ];
        };

        $grouped = collect($events)->groupBy('date');

        foreach ($grouped as $date => $dayEvents) {
            $day = (string) $date;

            if ($day > $periodStart) {
                $flushPeriod(CarbonImmutable::parse($day)->subDay()->toDateString());
                $periodStart = $day;
            }

            foreach ($dayEvents as $event) {
                $count += (int) $event['delta'];
                $minimum = min($minimum, $count);
                $maximum = max($maximum, $count);
                $gap = max(0, $required - $count);
                $maximumGap = max($maximumGap, $gap);

                if ($event['type'] === CrewProjectedManningEventType::SignOff->value) {
                    $hasDeparture = true;
                }

                if ($event['type'] === CrewProjectedManningEventType::Join->value) {
                    $hasIncoming = true;
                }

                if ($gap > 0 && $nextGapDate === null) {
                    $nextGapDate = $day;
                }
            }

            $periodCount = $count;
        }

        $flushPeriod($toDate);

        $currentGap = max(0, $required - $starting);
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
            'starting_count' => $starting,
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
        $fromDate = CarbonImmutable::parse(
            $from instanceof CarbonInterface ? $from->toDateString() : (string) $from,
            $timezone,
        )->toDateString();
        $toDate = CarbonImmutable::parse(
            $to instanceof CarbonInterface ? $to->toDateString() : (string) $to,
            $timezone,
        )->toDateString();

        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('Projection "from" date must be on or before "to" date.');
        }

        return [$fromDate, $toDate];
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
