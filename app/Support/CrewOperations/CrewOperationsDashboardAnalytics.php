<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewProjectedManningStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionAge;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\CrewMovements\CrewReliefStatusQuery;
use App\Support\CrewMovements\CrewTourStatusQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily Operations Dashboard payload for Crew Operations overview.
 *
 * Operational cockpit: pulse metrics, bounded action list, next-7-day movements,
 * and manning/relief risks. Projected coverage comes only from CrewProjectedManningQuery.
 */
final class CrewOperationsDashboardAnalytics
{
    private const ACTION_LIMIT = 10;

    private const RISK_LIMIT = 8;

    private const NEXT_DAYS = 7;

    private const PROJECTED_MANNING_HORIZON_DAYS = 30;

    private const PROJECTED_CRITICAL_POSITIONS_LIMIT = 5;

    public function __construct(
        private readonly CrewMovementCorrectionAge $correctionAge,
        private readonly CrewProjectedManningQuery $projectedManningQuery,
        private readonly CrewTourStatusQuery $tourStatusQuery = new CrewTourStatusQuery,
        private readonly CrewReliefStatusQuery $reliefStatusQuery = new CrewReliefStatusQuery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId, ?User $user): array
    {
        $permissions = CrewOperationsDashboardPagePermissions::for($user);
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $horizonEnd = $today->addDays(self::NEXT_DAYS - 1);
        $maxHomeDays = CrewOperationsSettings::maxHomeDays($companyId);

        $manningGaps = $permissions['vessel_manning']
            ? CrewOperationsManningGapQuery::forCompany($companyId, $today)
            : [
                'understaffed_positions' => 0,
                'total_shortfall' => 0,
                'items' => [],
            ];

        $projectedManning = $permissions['vessel_manning']
            ? $this->projectedManningSummary($companyId)
            : null;

        $tourBuckets = $this->tourStatusQuery->bucketCounts($companyId);
        $reliefResolved = $this->reliefStatusQuery->resolveActiveOnVessel($companyId);
        $onboardNow = $this->onboardNowCount($companyId);
        $joinsNext7 = $this->joinsInWindow($companyId, $today, $horizonEnd);
        $signoffsNext7 = (int) $tourBuckets['due_today'] + (int) $tourBuckets['due_within_7_days'];
        $signoffsOverdue = (int) $tourBuckets['overdue'];

        $nextSevenDays = $this->nextSevenDays($companyId, $today, $horizonEnd, $permissions['planning']);
        $actionRequired = $this->actionRequired(
            companyId: $companyId,
            today: $today,
            maxHomeDays: $maxHomeDays,
            manningGapItems: $manningGaps['items'],
            projectedManning: $projectedManning,
            tourBuckets: $tourBuckets,
            reliefResolved: $reliefResolved,
            canViewCorrections: $permissions['corrections_view'],
            canViewAssignments: $permissions['assignments'],
            canViewEmployees: $user?->can('employees.view') ?? false,
            canViewPlanning: $permissions['planning'],
            canViewVesselManning: $permissions['vessel_manning'],
        );
        $manningReliefRisks = $this->manningReliefRisks(
            manningGapItems: $manningGaps['items'],
            projectedManning: $projectedManning,
            reliefResolved: $reliefResolved,
            companyId: $companyId,
            canViewPlanning: $permissions['planning'],
            canViewVesselManning: $permissions['vessel_manning'],
            canViewAssignments: $permissions['assignments'],
        );

        return [
            'today' => $today->toDateString(),
            'company_timezone' => $timezone,
            'daily_pulse' => [
                'onboard_now' => $onboardNow,
                'joins_next_7_days' => $joinsNext7,
                'signoffs_next_7_days' => $signoffsNext7,
                'signoffs_overdue' => $signoffsOverdue,
                'coverage_risks' => [
                    'current' => (int) $manningGaps['understaffed_positions'],
                    'upcoming' => $projectedManning !== null
                        ? (int) $projectedManning['future_gap_positions']
                        : 0,
                ],
            ],
            'action_required' => $actionRequired,
            'next_seven_days' => $nextSevenDays,
            'manning_relief_risks' => $manningReliefRisks,
            'projected_manning' => $projectedManning,
            'max_home_days' => $maxHomeDays,
            'can' => $permissions,
        ];
    }

    private function onboardNowCount(int $companyId): int
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereHas('currentPhase', function ($phase): void {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->where('status', CrewPhaseStatus::Active->value);
            })
            ->count();
    }

    private function joinsInWindow(
        int $companyId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        $planningJoins = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereBetween('planned_join_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'crew_assignment_id', 'planned_join_date', 'employee_id']);

        $linkedAssignmentIds = $planningJoins
            ->pluck('crew_assignment_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        $assignmentJoins = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active])
            ->whereNotNull('planned_join_at')
            ->whereDate('planned_join_at', '>=', $from->toDateString())
            ->whereDate('planned_join_at', '<=', $to->toDateString())
            ->whereDoesntHave('currentPhase', function ($phase): void {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->whereNotNull('actual_start_at');
            })
            ->when($linkedAssignmentIds !== [], fn ($q) => $q->whereNotIn('id', $linkedAssignmentIds))
            ->count();

        return $planningJoins->count() + $assignmentJoins;
    }

    /**
     * @return list<array{date: string, label: string, joins: int, signoffs: int}>
     */
    private function nextSevenDays(
        int $companyId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $canViewPlanning,
    ): array {
        $days = [];

        for ($i = 0; $i < self::NEXT_DAYS; $i++) {
            $date = $from->addDays($i)->toDateString();
            $days[$date] = [
                'date' => $date,
                'label' => $this->dayLabel($from->addDays($i), $from),
                'joins' => 0,
                'signoffs' => 0,
            ];
        }

        if ($canViewPlanning) {
            $planningJoins = CrewPlanningAssignment::query()
                ->where('company_id', $companyId)
                ->whereBetween('planned_join_date', [$from->toDateString(), $to->toDateString()])
                ->get(['planned_join_date', 'crew_assignment_id']);

            foreach ($planningJoins as $row) {
                $date = $row->planned_join_date?->toDateString();

                if ($date !== null && isset($days[$date])) {
                    $days[$date]['joins']++;
                }
            }

            $linkedAssignmentIds = $planningJoins
                ->pluck('crew_assignment_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->all();
        } else {
            $linkedAssignmentIds = [];
        }

        $assignmentJoins = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active])
            ->whereNotNull('planned_join_at')
            ->whereDate('planned_join_at', '>=', $from->toDateString())
            ->whereDate('planned_join_at', '<=', $to->toDateString())
            ->whereDoesntHave('currentPhase', function ($phase): void {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->whereNotNull('actual_start_at');
            })
            ->when($linkedAssignmentIds !== [], fn ($q) => $q->whereNotIn('id', $linkedAssignmentIds))
            ->get(['planned_join_at']);

        foreach ($assignmentJoins as $assignment) {
            $date = $assignment->planned_join_at?->toDateString();

            if ($date !== null && isset($days[$date])) {
                $days[$date]['joins']++;
            }
        }

        $signoffs = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNotNull('planned_signoff_at')
            ->whereDate('planned_signoff_at', '>=', $from->toDateString())
            ->whereDate('planned_signoff_at', '<=', $to->toDateString())
            ->whereHas('currentPhase', function ($phase): void {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->where('status', CrewPhaseStatus::Active->value);
            })
            ->get(['planned_signoff_at']);

        foreach ($signoffs as $assignment) {
            $date = $assignment->planned_signoff_at?->toDateString();

            if ($date !== null && isset($days[$date])) {
                $days[$date]['signoffs']++;
            }
        }

        return array_values($days);
    }

    private function dayLabel(CarbonImmutable $date, CarbonImmutable $today): string
    {
        if ($date->toDateString() === $today->toDateString()) {
            return 'Today';
        }

        if ($date->toDateString() === $today->addDay()->toDateString()) {
            return 'Tomorrow';
        }

        return $date->format('M j');
    }

    /**
     * @param  list<array<string, mixed>>  $manningGapItems
     * @param  array<string, mixed>|null  $projectedManning
     * @param  array<string, int>  $tourBuckets
     * @param  Collection<int, mixed>  $reliefResolved
     * @return list<array<string, mixed>>
     */
    private function actionRequired(
        int $companyId,
        CarbonImmutable $today,
        int $maxHomeDays,
        array $manningGapItems,
        ?array $projectedManning,
        array $tourBuckets,
        Collection $reliefResolved,
        bool $canViewCorrections,
        bool $canViewAssignments,
        bool $canViewEmployees,
        bool $canViewPlanning,
        bool $canViewVesselManning,
    ): array {
        $items = [];

        foreach ($manningGapItems as $gap) {
            if (count($items) >= self::ACTION_LIMIT) {
                break;
            }

            $items[] = [
                'type' => 'current_manning_gap',
                'severity' => 'critical',
                'title' => $gap['vessel_name'],
                'subtitle' => $gap['rank_name'],
                'problem' => sprintf(
                    'Short %d now — %d of %d actually onboard',
                    $gap['gap'],
                    $gap['actual_count'],
                    $gap['required_count'],
                ),
                'meta' => null,
                'href' => route('organization.vessel-manning.show', ['vessel' => $gap['vessel_id']]),
            ];
        }

        if ((int) ($tourBuckets['overdue'] ?? 0) > 0 && count($items) < self::ACTION_LIMIT) {
            $items[] = [
                'type' => 'signoff_overdue',
                'severity' => 'critical',
                'title' => 'Tour sign-off overdue',
                'subtitle' => null,
                'problem' => sprintf('%d crew past planned sign-off', $tourBuckets['overdue']),
                'meta' => null,
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.index', [
                        'tour_status' => 'overdue',
                    ])
                    : null,
            ];
        }

        $dueTodayNoRelief = $this->reliefItemsMatching(
            $reliefResolved,
            fn ($result): bool => $result->daysUntilSignoff === 0
                && $result->status === CrewReliefStatus::NoRelief,
        );

        if ($dueTodayNoRelief > 0 && count($items) < self::ACTION_LIMIT) {
            $items[] = [
                'type' => 'signoff_due_today_no_relief',
                'severity' => 'critical',
                'title' => 'Sign-off due today — no relief',
                'subtitle' => null,
                'problem' => sprintf('%d assignment(s) due today without relief', $dueTodayNoRelief),
                'meta' => $today->toDateString(),
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.index', [
                        'tour_status' => 'due_today',
                        'relief_status' => CrewReliefStatus::NoRelief->value,
                    ])
                    : null,
            ];
        }

        $criticalRelief = $this->reliefItemsMatching(
            $reliefResolved,
            fn ($result): bool => $result->risk === CrewReliefRisk::Critical,
        );

        if ($criticalRelief > 0 && count($items) < self::ACTION_LIMIT) {
            $items[] = [
                'type' => 'critical_relief_risk',
                'severity' => 'critical',
                'title' => 'Critical relief risk',
                'subtitle' => null,
                'problem' => sprintf('%d assignment(s) at critical relief risk', $criticalRelief),
                'meta' => null,
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.index', [
                        'relief_risk' => CrewReliefRisk::Critical->value,
                    ])
                    : null,
            ];
        }

        $imminentNotReady = $this->reliefItemsMatching(
            $reliefResolved,
            fn ($result): bool => $result->daysUntilSignoff !== null
                && $result->daysUntilSignoff >= 0
                && $result->daysUntilSignoff <= 7
                && in_array($result->status, CrewReliefStatus::notReady(), true)
                && $result->status !== CrewReliefStatus::NoRelief,
        );

        if ($imminentNotReady > 0 && count($items) < self::ACTION_LIMIT) {
            $items[] = [
                'type' => 'imminent_signoff_relief_not_ready',
                'severity' => 'warning',
                'title' => 'Imminent sign-off — relief not ready',
                'subtitle' => null,
                'problem' => sprintf(
                    '%d assignment(s) signing off within 7 days with relief not ready',
                    $imminentNotReady,
                ),
                'meta' => null,
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.index', [
                        'tour_status' => 'due_within_7_days',
                        'relief_not_ready' => 1,
                    ])
                    : null,
            ];
        }

        if ($projectedManning !== null) {
            foreach ($projectedManning['critical_positions'] as $position) {
                if (count($items) >= self::ACTION_LIMIT) {
                    break;
                }

                if ($position['status'] !== CrewProjectedManningStatus::FutureGap->value) {
                    continue;
                }

                $items[] = [
                    'type' => 'projected_future_gap',
                    'severity' => 'warning',
                    'title' => $position['vessel_name'],
                    'subtitle' => $position['rank_name'],
                    'problem' => sprintf(
                        'Projected future gap — max short %d',
                        $position['maximum_gap'],
                    ),
                    'meta' => $position['next_gap_date'],
                    'href' => $this->projectedGapHref(
                        $canViewPlanning,
                        $canViewVesselManning,
                        (int) $position['vessel_id'],
                        (int) $position['rank_id'],
                    ),
                ];
            }
        }

        if ($canViewCorrections && count($items) < self::ACTION_LIMIT) {
            $corrections = $this->movementCorrectionSummary($companyId);

            if ($corrections['overdue'] > 0) {
                $items[] = [
                    'type' => 'overdue_movement_correction',
                    'severity' => 'warning',
                    'title' => 'Overdue movement corrections',
                    'subtitle' => null,
                    'problem' => sprintf('%d correction(s) past review SLA', $corrections['overdue']),
                    'meta' => null,
                    'href' => $corrections['url'],
                ];
            }
        }

        $this->appendEmployeeActions($items, $companyId, $maxHomeDays, $canViewEmployees);

        return array_slice($items, 0, self::ACTION_LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function appendEmployeeActions(
        array &$items,
        int $companyId,
        int $maxHomeDays,
        bool $canViewEmployees,
    ): void {
        if (count($items) >= self::ACTION_LIMIT) {
            return;
        }

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->with(['company', 'rank'])
            ->get();

        $resolver = new CrewAssignmentStatusResolver;
        $seen = [];

        foreach ($employees as $employee) {
            if (count($items) >= self::ACTION_LIMIT) {
                return;
            }

            $resolved = $resolver->forEmployee($employee);

            if ($resolved['status'] !== 'movement_update_required') {
                continue;
            }

            $items[] = [
                'type' => 'needs_update',
                'severity' => 'warning',
                'title' => $employee->name,
                'subtitle' => $employee->rank?->name,
                'problem' => $resolved['warning'] ?? 'Assignment needs an update',
                'meta' => null,
                'href' => $canViewEmployees
                    ? route('organization.employees.show', ['employee' => $employee->id])
                    : null,
            ];
            $seen[$employee->id] = true;
        }

        foreach ($employees as $employee) {
            if (count($items) >= self::ACTION_LIMIT) {
                return;
            }

            if (isset($seen[$employee->id])) {
                continue;
            }

            $resolved = $resolver->forEmployee($employee);

            if ($resolved['status'] !== 'in_home' || $resolved['in_home_days'] === null) {
                continue;
            }

            if ($resolved['in_home_days'] <= $maxHomeDays) {
                continue;
            }

            $items[] = [
                'type' => 'overdue_home',
                'severity' => 'warning',
                'title' => $employee->name,
                'subtitle' => $employee->rank?->name,
                'problem' => sprintf(
                    'In home %d days — exceeds %d day limit',
                    $resolved['in_home_days'],
                    $maxHomeDays,
                ),
                'meta' => null,
                'href' => $canViewEmployees
                    ? route('organization.employees.show', ['employee' => $employee->id])
                    : null,
            ];
        }
    }

    /**
     * @param  Collection<int, mixed>  $reliefResolved
     * @param  callable(mixed): bool  $predicate
     */
    private function reliefItemsMatching(Collection $reliefResolved, callable $predicate): int
    {
        $count = 0;

        foreach ($reliefResolved as $result) {
            if ($predicate($result)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $manningGapItems
     * @param  array<string, mixed>|null  $projectedManning
     * @param  Collection<int, mixed>  $reliefResolved
     * @return list<array<string, mixed>>
     */
    private function manningReliefRisks(
        array $manningGapItems,
        ?array $projectedManning,
        Collection $reliefResolved,
        int $companyId,
        bool $canViewPlanning,
        bool $canViewVesselManning,
        bool $canViewAssignments,
    ): array {
        $items = [];

        if ($canViewVesselManning) {
            foreach ($manningGapItems as $gap) {
                if (count($items) >= self::RISK_LIMIT) {
                    break;
                }

                $items[] = [
                    'kind' => 'actual',
                    'risk' => 'Gap now',
                    'vessel_id' => (int) $gap['vessel_id'],
                    'vessel_name' => (string) $gap['vessel_name'],
                    'rank_id' => (int) $gap['rank_id'],
                    'rank_name' => (string) $gap['rank_name'],
                    'when' => 'Now',
                    'href' => route('organization.vessel-manning.show', ['vessel' => $gap['vessel_id']]),
                    'employee_name' => null,
                ];
            }
        }

        if ($projectedManning !== null) {
            foreach ($projectedManning['critical_positions'] as $position) {
                if (count($items) >= self::RISK_LIMIT) {
                    break;
                }

                if ($position['status'] !== CrewProjectedManningStatus::FutureGap->value) {
                    continue;
                }

                $items[] = [
                    'kind' => 'projected',
                    'risk' => 'Future gap',
                    'vessel_id' => (int) $position['vessel_id'],
                    'vessel_name' => (string) $position['vessel_name'],
                    'rank_id' => (int) $position['rank_id'],
                    'rank_name' => (string) $position['rank_name'],
                    'when' => $position['next_gap_date'] ?? 'Upcoming',
                    'href' => $this->projectedGapHref(
                        $canViewPlanning,
                        $canViewVesselManning,
                        (int) $position['vessel_id'],
                        (int) $position['rank_id'],
                    ),
                    'employee_name' => null,
                ];
            }
        }

        $assignmentMeta = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $reliefResolved->keys()->all() ?: [0])
            ->with(['vessel:id,name', 'rank:id,name', 'employee:id,name'])
            ->get()
            ->keyBy('id');

        foreach ($reliefResolved as $assignmentId => $result) {
            if (count($items) >= self::RISK_LIMIT) {
                break;
            }

            $assignment = $assignmentMeta->get($assignmentId);

            if ($assignment === null) {
                continue;
            }

            $riskLabel = null;

            if ($result->risk === CrewReliefRisk::Critical) {
                $riskLabel = 'Critical relief';
            } elseif ($result->status === CrewReliefStatus::NoRelief
                && $result->daysUntilSignoff !== null
                && $result->daysUntilSignoff >= 0
                && $result->daysUntilSignoff <= 14) {
                $riskLabel = 'No relief';
            } elseif (in_array($result->status, CrewReliefStatus::notReady(), true)
                && $result->daysUntilSignoff !== null
                && $result->daysUntilSignoff >= 0
                && $result->daysUntilSignoff <= 7
                && $result->status !== CrewReliefStatus::NoRelief) {
                $riskLabel = 'Relief not ready';
            }

            if ($riskLabel === null) {
                continue;
            }

            $items[] = [
                'kind' => 'relief',
                'risk' => $riskLabel,
                'vessel_id' => $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null,
                'vessel_name' => $assignment->vessel?->name ?? 'Unassigned vessel',
                'rank_id' => $assignment->rank_id !== null ? (int) $assignment->rank_id : null,
                'rank_name' => $assignment->rank?->name ?? 'Unassigned rank',
                'when' => $result->sourcePlannedSignoffDate ?? 'Upcoming',
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.show', [
                        'assignment' => $assignmentId,
                    ])
                    : null,
                'employee_name' => $canViewAssignments
                    ? $assignment->employee?->name
                    : null,
            ];
        }

        return $items;
    }

    private function projectedGapHref(
        bool $canViewPlanning,
        bool $canViewVesselManning,
        int $vesselId,
        int $rankId,
    ): ?string {
        if ($canViewPlanning) {
            return route('organization.crew-planning.index', [
                'vessel_id' => $vesselId,
                'rank_id' => $rankId,
            ]);
        }

        if ($canViewVesselManning) {
            if ($vesselId > 0) {
                return route('organization.vessel-manning.show', [
                    'vessel' => $vesselId,
                ]);
            }

            return route('organization.vessel-manning.index');
        }

        return null;
    }

    /**
     * @return array{
     *     horizon_days: int,
     *     from: string,
     *     to: string,
     *     current_gap_positions: int,
     *     future_gap_positions: int,
     *     covered_positions: int,
     *     overlap_positions: int,
     *     projected_shortfall_days: int,
     *     next_gap_date: string|null,
     *     critical_positions: list<array<string, mixed>>
     * }
     */
    private function projectedManningSummary(int $companyId): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $from = CarbonImmutable::now($timezone)->toDateString();
        $to = CarbonImmutable::parse($from, $timezone)
            ->addDays(self::PROJECTED_MANNING_HORIZON_DAYS)
            ->toDateString();

        $projection = $this->projectedManningQuery->forCompany(
            $companyId,
            $from,
            $to,
        );

        $gapItems = collect($projection['items'])
            ->filter(fn (array $item): bool => in_array($item['status'], [
                CrewProjectedManningStatus::CurrentGap->value,
                CrewProjectedManningStatus::FutureGap->value,
            ], true))
            ->sort(function (array $a, array $b): int {
                $statusRank = [
                    CrewProjectedManningStatus::CurrentGap->value => 0,
                    CrewProjectedManningStatus::FutureGap->value => 1,
                ];

                $statusCmp = ($statusRank[$a['status']] ?? 9) <=> ($statusRank[$b['status']] ?? 9);

                if ($statusCmp !== 0) {
                    return $statusCmp;
                }

                $aDate = $a['next_gap_date'] ?? '9999-12-31';
                $bDate = $b['next_gap_date'] ?? '9999-12-31';
                $dateCmp = strcmp((string) $aDate, (string) $bDate);

                if ($dateCmp !== 0) {
                    return $dateCmp;
                }

                return ((int) $b['maximum_gap']) <=> ((int) $a['maximum_gap']);
            })
            ->values();

        $criticalPositions = $gapItems
            ->take(self::PROJECTED_CRITICAL_POSITIONS_LIMIT)
            ->map(fn (array $item): array => [
                'vessel_id' => (int) $item['vessel_id'],
                'vessel_name' => (string) $item['vessel_name'],
                'rank_id' => (int) $item['rank_id'],
                'rank_name' => (string) $item['rank_name'],
                'required_count' => (int) $item['required_count'],
                'minimum_projected_count' => (int) $item['minimum_projected_count'],
                'maximum_gap' => (int) $item['maximum_gap'],
                'next_gap_date' => $item['next_gap_date'],
                'status' => (string) $item['status'],
                'status_label' => (string) $item['status_label'],
            ])
            ->all();

        $nextGapDate = $gapItems
            ->pluck('next_gap_date')
            ->filter(fn (mixed $date): bool => is_string($date) && $date !== '')
            ->sort()
            ->first();

        return [
            'horizon_days' => self::PROJECTED_MANNING_HORIZON_DAYS,
            'from' => $projection['from'],
            'to' => $projection['to'],
            'current_gap_positions' => (int) $projection['summary']['current_gap_positions'],
            'future_gap_positions' => (int) $projection['summary']['future_gap_positions'],
            'covered_positions' => (int) $projection['summary']['covered_positions'],
            'overlap_positions' => (int) $projection['summary']['overlap_positions'],
            'projected_shortfall_days' => (int) $projection['summary']['total_projected_shortfall_days'],
            'next_gap_date' => is_string($nextGapDate) ? $nextGapDate : null,
            'critical_positions' => $criticalPositions,
        ];
    }

    /**
     * @return array{pending: int, overdue: int, url: string}
     */
    private function movementCorrectionSummary(int $companyId): array
    {
        $timezone = (string) (Company::query()
            ->whereKey($companyId)
            ->value('timezone') ?? config('app.timezone', 'UTC'));
        $counts = $this->correctionAge->pendingCounts(
            CrewMovementCorrection::query()->where('company_id', $companyId),
            $timezone,
        );

        return [
            'pending' => $counts['pending'],
            'overdue' => $counts['overdue'],
            'url' => route('organization.crew-movement-corrections.index'),
        ];
    }
}
