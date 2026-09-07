<?php

namespace App\Support\VesselManning;

use App\Enums\CrewReliefStatus;
use App\Enums\VesselManningHealthStatus;
use App\Models\CrewAssignment;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Support\CrewMovements\CrewMobilisationReadinessResolver;
use App\Support\CrewMovements\CrewReliefPlanningLoader;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewMovements\CurrentOnboardCrewQuery;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Vessel-level manning health derived from VesselManning, current P4 onboard,
 * and CrewProjectedManningQuery. No independent coverage engine.
 */
final class VesselManningHealthQuery
{
    public const HORIZON_DAYS = 30;

    public function __construct(
        private readonly CrewProjectedManningQuery $projectedManningQuery = new CrewProjectedManningQuery,
        private readonly CrewReliefReadinessResolver $reliefResolver = new CrewReliefReadinessResolver,
        private readonly CrewReliefPlanningLoader $reliefLoader = new CrewReliefPlanningLoader,
        private readonly CrewMobilisationReadinessResolver $mobilisationResolver = new CrewMobilisationReadinessResolver,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forVessel(int $companyId, int $vesselId, ?User $user): ?array
    {
        if (! $this->canViewManning($user)) {
            return null;
        }

        $vessel = Vessel::query()
            ->where('company_id', $companyId)
            ->whereKey($vesselId)
            ->first();

        if ($vessel === null) {
            return null;
        }

        $includeCrewDetails = $this->canViewAssignmentDetails($user);
        $snapshot = $this->snapshot($companyId, $vesselId, includeDetails: true, user: $user);

        return $this->presentShow(
            $companyId,
            $vesselId,
            $snapshot,
            $includeCrewDetails,
            $user,
        );
    }

    /**
     * Compact health keyed by vessel id for the company. One projection + one onboard query.
     *
     * @return array<int, array{
     *     status: string,
     *     status_label: string,
     *     required: int,
     *     onboard: int,
     *     current_gap: int,
     *     future_gap: int,
     *     next_gap_date: string|null,
     *     reason: string
     * }>
     */
    public function compactByVessel(int $companyId, ?User $user = null): array
    {
        if ($user !== null && ! $this->canViewManning($user)) {
            return [];
        }

        $snapshot = $this->snapshot($companyId, null, includeDetails: false, user: $user);
        $vesselIds = Vessel::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $compact = [];

        foreach ($vesselIds as $vesselId) {
            $compact[$vesselId] = $this->presentCompact($vesselId, $snapshot);
        }

        return $compact;
    }

    /**
     * @param  array<int, array{status: string, current_gap: int, next_gap_date: string|null, ...}>  $compactByVessel
     * @return list<int>
     */
    public static function sortedVesselIds(array $compactByVessel, array $nameById = []): array
    {
        $ids = array_keys($compactByVessel);

        usort($ids, function (int|string $a, int|string $b) use ($compactByVessel, $nameById): int {
            $left = $compactByVessel[(int) $a];
            $right = $compactByVessel[(int) $b];
            $leftStatus = VesselManningHealthStatus::tryFrom((string) $left['status']) ?? VesselManningHealthStatus::NotConfigured;
            $rightStatus = VesselManningHealthStatus::tryFrom((string) $right['status']) ?? VesselManningHealthStatus::NotConfigured;

            $statusCmp = $leftStatus->sortRank() <=> $rightStatus->sortRank();

            if ($statusCmp !== 0) {
                return $statusCmp;
            }

            if ($leftStatus === VesselManningHealthStatus::Critical) {
                $gapCmp = ((int) $right['current_gap']) <=> ((int) $left['current_gap']);

                if ($gapCmp !== 0) {
                    return $gapCmp;
                }
            }

            if ($leftStatus === VesselManningHealthStatus::AtRisk) {
                $leftDate = is_string($left['next_gap_date'] ?? null) ? (string) $left['next_gap_date'] : '9999-12-31';
                $rightDate = is_string($right['next_gap_date'] ?? null) ? (string) $right['next_gap_date'] : '9999-12-31';
                $dateCmp = strcmp($leftDate, $rightDate);

                if ($dateCmp !== 0) {
                    return $dateCmp;
                }
            }

            $leftName = $nameById[(int) $a] ?? '';
            $rightName = $nameById[(int) $b] ?? '';

            return strcasecmp($leftName, $rightName);
        });

        return array_map(intval(...), array_values($ids));
    }

    /**
     * @return array{
     *     timezone: string,
     *     from: string,
     *     to: string,
     *     horizon_days: int,
     *     manning: Collection<int, VesselManning>,
     *     projection: array<string, mixed>,
     *     onboard_by_key: array<string, int>,
     *     onboard_assignments: Collection<int, CrewAssignment>
     * }
     */
    private function snapshot(int $companyId, ?int $vesselId, bool $includeDetails, ?User $user): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $from = CarbonImmutable::now($timezone)->toDateString();
        $to = CarbonImmutable::parse($from, $timezone)
            ->addDays(self::HORIZON_DAYS)
            ->toDateString();

        $manning = VesselManning::query()
            ->where('company_id', $companyId)
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->with(['vessel:id,name', 'rank:id,name'])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->get();

        $projection = $this->projectedManningQuery->forCompany(
            $companyId,
            $from,
            $to,
            $vesselId,
        );

        $onboardQuery = CurrentOnboardCrewQuery::applyConstraint(CrewAssignment::query(), $companyId)
            ->whereNotNull('rank_id')
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId));

        if ($includeDetails) {
            $onboardQuery->with([
                'employee' => fn ($q) => $q
                    ->where('company_id', $companyId)
                    ->select(['id', 'company_id', 'name', 'employee_no', 'status']),
                'rank:id,name',
                'currentPhase' => fn ($q) => $q->where('company_id', $companyId),
            ]);
        }

        $onboardAssignments = $onboardQuery->get([
            'id',
            'company_id',
            'employee_id',
            'vessel_id',
            'rank_id',
            'status',
            'planned_signoff_at',
            'current_phase_id',
        ]);

        $onboardByKey = [];

        foreach ($onboardAssignments as $assignment) {
            if ((int) $assignment->company_id !== $companyId) {
                continue;
            }

            $key = $this->vesselRankKey((int) $assignment->vessel_id, (int) $assignment->rank_id);
            $onboardByKey[$key] = ($onboardByKey[$key] ?? 0) + 1;
        }

        return [
            'timezone' => $timezone,
            'from' => $from,
            'to' => $to,
            'horizon_days' => self::HORIZON_DAYS,
            'manning' => $manning,
            'projection' => $projection,
            'onboard_by_key' => $onboardByKey,
            'onboard_assignments' => $onboardAssignments,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function presentShow(
        int $companyId,
        int $vesselId,
        array $snapshot,
        bool $includeCrewDetails,
        ?User $user,
    ): array {
        $rows = $this->buildRankRows($companyId, $vesselId, $snapshot, $includeCrewDetails, $user, loadRelief: true);
        $summary = $this->summarizeVessel($vesselId, $rows, $snapshot);

        $signoffsWithin14 = 0;
        $readyReliefs = 0;
        $today = CarbonImmutable::parse($snapshot['from'], $snapshot['timezone'])->startOfDay();
        $within14 = $today->addDays(14)->toDateString();

        foreach ($snapshot['onboard_assignments'] as $assignment) {
            if ((int) $assignment->vessel_id !== $vesselId) {
                continue;
            }

            $signoff = $assignment->planned_signoff_at?->copy()->timezone($snapshot['timezone'])->toDateString();

            if ($signoff !== null && $signoff >= $snapshot['from'] && $signoff <= $within14) {
                $signoffsWithin14++;
            }
        }

        foreach ($rows as $row) {
            $readyReliefs += (int) ($row['ready_relief_count'] ?? 0);
        }

        $status = VesselManningHealthStatus::from($summary['status']);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'horizon_days' => $snapshot['horizon_days'],
            'from' => $snapshot['from'],
            'to' => $snapshot['to'],
            'reason' => $summary['reason'],
            'required' => $summary['required'],
            'onboard' => $summary['onboard'],
            'current_gap' => $summary['current_gap'],
            'future_gap' => $summary['future_gap'],
            'next_gap_date' => $summary['next_gap_date'],
            'overlap_excess' => $summary['overlap_excess'],
            'signing_off_within_14_days' => $signoffsWithin14,
            'ready_reliefs' => $readyReliefs,
            'projected_shortfall_days' => $summary['projected_shortfall_days'],
            'include_crew_details' => $includeCrewDetails,
            'ranks' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     status: string,
     *     status_label: string,
     *     required: int,
     *     onboard: int,
     *     current_gap: int,
     *     future_gap: int,
     *     next_gap_date: string|null,
     *     reason: string
     * }
     */
    private function presentCompact(int $vesselId, array $snapshot): array
    {
        $rows = $this->buildRankRows(0, $vesselId, $snapshot, includeDetails: false, user: null, loadRelief: false);
        $summary = $this->summarizeVessel($vesselId, $rows, $snapshot);
        $status = VesselManningHealthStatus::from($summary['status']);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'required' => $summary['required'],
            'onboard' => $summary['onboard'],
            'current_gap' => $summary['current_gap'],
            'future_gap' => $summary['future_gap'],
            'next_gap_date' => $summary['next_gap_date'],
            'reason' => $this->compactReason($status, $summary),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function buildRankRows(
        int $companyId,
        int $vesselId,
        array $snapshot,
        bool $includeDetails,
        ?User $user,
        bool $loadRelief = false,
    ): array {
        $manning = $snapshot['manning']->filter(
            fn (VesselManning $row): bool => (int) $row->vessel_id === $vesselId && (int) $row->required_count >= 1,
        )->values();

        if ($manning->isEmpty()) {
            return [];
        }

        $projectionByRank = [];

        foreach ($snapshot['projection']['items'] as $item) {
            if ((int) $item['vessel_id'] !== $vesselId) {
                continue;
            }

            $projectionByRank[(int) $item['rank_id']] = $item;
        }

        $onboardForVessel = $snapshot['onboard_assignments']->filter(
            fn (CrewAssignment $assignment): bool => (int) $assignment->vessel_id === $vesselId
                && (int) $assignment->company_id === ($companyId > 0 ? $companyId : (int) $assignment->company_id),
        )->values();

        $reliefBySource = collect();
        $mobilisationByAssignment = collect();

        if ($loadRelief && $companyId > 0) {
            $sourceIds = $onboardForVessel->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $reliefBySource = $this->reliefLoader->forSourceAssignmentIds($companyId, $sourceIds);

            $linkedReliefAssignments = $reliefBySource
                ->map(fn ($plan) => $plan->crewAssignment)
                ->filter()
                ->values();

            if ($includeDetails && $linkedReliefAssignments->isNotEmpty()) {
                $this->mobilisationResolver->attachForAssignments($linkedReliefAssignments, $companyId, $user);
                $mobilisationByAssignment = $linkedReliefAssignments->keyBy(fn (CrewAssignment $assignment): int => (int) $assignment->id);
            }
        }

        $rows = [];

        foreach ($manning as $line) {
            $rankId = (int) $line->rank_id;
            $required = (int) $line->required_count;
            $key = $this->vesselRankKey($vesselId, $rankId);
            $onboard = (int) ($snapshot['onboard_by_key'][$key] ?? 0);
            $projected = $projectionByRank[$rankId] ?? null;
            $minimumProjected = $projected !== null ? (int) $projected['minimum_projected_count'] : $onboard;
            $maximumProjected = $projected !== null ? (int) $projected['maximum_projected_count'] : $onboard;
            $maximumGap = $projected !== null ? (int) $projected['maximum_gap'] : max(0, $required - $onboard);
            $nextGapDate = $projected !== null && is_string($projected['next_gap_date'] ?? null)
                ? (string) $projected['next_gap_date']
                : ($onboard < $required ? (string) $snapshot['from'] : null);
            $hasOverlap = $projected !== null && (bool) $projected['has_overlap'];
            $overlapExcess = $hasOverlap ? max(0, $maximumProjected - $required) : 0;
            $currentGap = max(0, $required - $onboard);
            $futureGap = $currentGap === 0 ? max(0, $required - $minimumProjected) : 0;

            if ($currentGap > 0) {
                $status = VesselManningHealthStatus::Critical;
            } elseif ($maximumGap > 0 && $minimumProjected < $required) {
                $status = VesselManningHealthStatus::AtRisk;
            } else {
                $status = VesselManningHealthStatus::Healthy;
            }

            $rankOnboard = $onboardForVessel->filter(
                fn (CrewAssignment $assignment): bool => (int) $assignment->rank_id === $rankId,
            )->values();

            $reliefContext = $this->reliefContextForRank(
                $rankOnboard,
                $reliefBySource,
                $mobilisationByAssignment,
                $includeDetails,
            );

            $signoffs = [];

            if ($includeDetails) {
                foreach ($rankOnboard as $assignment) {
                    $signoff = $assignment->planned_signoff_at?->copy()->timezone($snapshot['timezone'])->toDateString();

                    if ($signoff === null) {
                        continue;
                    }

                    $employee = $assignment->employee;

                    $signoffs[] = [
                        'assignment_id' => (int) $assignment->id,
                        'employee_id' => $employee !== null ? (int) $employee->id : null,
                        'employee_name' => $employee !== null ? (string) $employee->name : null,
                        'planned_signoff_at' => $signoff,
                    ];
                }
            }

            $rows[] = [
                'rank_id' => $rankId,
                'rank_name' => (string) ($line->rank?->name ?? ''),
                'required' => $required,
                'onboard' => $onboard,
                'projected' => $minimumProjected,
                'current_gap' => $currentGap,
                'future_gap' => $futureGap,
                'next_gap_date' => $status === VesselManningHealthStatus::AtRisk ? $nextGapDate : ($status === VesselManningHealthStatus::Critical ? $snapshot['from'] : null),
                'status' => $status->value,
                'status_label' => $status->label(),
                'reason' => $this->rankReason($status, $line->rank?->name ?? '', $required, $onboard, $minimumProjected, $nextGapDate, $signoffs, $reliefContext, $overlapExcess),
                'overlap_excess' => $overlapExcess,
                'relief_summary' => $reliefContext['summary'],
                'relief_status' => $reliefContext['status'],
                'relief_status_label' => $reliefContext['status_label'],
                'ready_relief_count' => $reliefContext['ready_count'],
                'signoffs' => $includeDetails ? $signoffs : [],
                'reliefs' => $includeDetails ? $reliefContext['reliefs'] : [],
                'mobilisation_readiness_label' => $reliefContext['mobilisation_readiness_label'],
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, CrewAssignment>  $rankOnboard
     * @param  Collection<int, mixed>  $reliefBySource
     * @param  Collection<int, CrewAssignment>  $mobilisationByAssignment
     * @return array{
     *     summary: string,
     *     status: string|null,
     *     status_label: string|null,
     *     ready_count: int,
     *     mobilisation_readiness_label: string|null,
     *     reliefs: list<array<string, mixed>>
     * }
     */
    private function reliefContextForRank(
        Collection $rankOnboard,
        Collection $reliefBySource,
        Collection $mobilisationByAssignment,
        bool $includeDetails,
    ): array {
        if ($rankOnboard->isEmpty()) {
            return [
                'summary' => 'No Relief',
                'status' => CrewReliefStatus::NoRelief->value,
                'status_label' => CrewReliefStatus::NoRelief->label(),
                'ready_count' => 0,
                'mobilisation_readiness_label' => null,
                'reliefs' => [],
            ];
        }

        $reliefs = [];
        $priority = [
            CrewReliefStatus::NoRelief->value => 0,
            CrewReliefStatus::ReliefPlanned->value => 1,
            CrewReliefStatus::AssignmentCreated->value => 2,
            CrewReliefStatus::Mobilising->value => 3,
            CrewReliefStatus::ReadyToJoin->value => 4,
            CrewReliefStatus::ReliefOnboard->value => 5,
        ];
        $worst = CrewReliefStatus::ReliefOnboard;
        $readyCount = 0;
        $hasAnyRelief = false;
        $mobilisationLabel = null;

        foreach ($rankOnboard as $source) {
            $plan = $reliefBySource->get((int) $source->id);
            $result = $this->reliefResolver->forPreloadedPlan($source, $plan);
            $status = $result->status;

            if ($status !== CrewReliefStatus::NoRelief) {
                $hasAnyRelief = true;
            }

            if ($status->isReadyOrOnboard()) {
                $readyCount++;
            }

            if (($priority[$status->value] ?? 0) < ($priority[$worst->value] ?? 0)) {
                $worst = $status;
            }

            $linked = $plan?->crewAssignment;
            $readiness = null;

            if ($linked !== null && $mobilisationByAssignment->has((int) $linked->id)) {
                $readinessResult = $linked->mobilisation_readiness ?? null;

                if ($readinessResult !== null && $readinessResult->applies) {
                    $readiness = $readinessResult->presentationLabel();
                    $mobilisationLabel ??= $readiness;
                }
            }

            if ($includeDetails) {
                $reliefs[] = [
                    'source_assignment_id' => (int) $source->id,
                    'source_employee_name' => $source->employee !== null ? (string) $source->employee->name : null,
                    'source_planned_signoff_at' => $result->sourcePlannedSignoffDate,
                    'relief_status' => $status->value,
                    'relief_status_label' => $status->label(),
                    'relief_employee_name' => $result->reliefEmployee['name'] ?? null,
                    'relief_phase_label' => $result->reliefPhase['label'] ?? null,
                    'relief_planned_join_date' => $result->reliefPlannedJoinDate,
                    'mobilisation_readiness_label' => $readiness,
                ];
            }
        }

        if (! $hasAnyRelief) {
            $worst = CrewReliefStatus::NoRelief;
        }

        $summary = match ($worst) {
            CrewReliefStatus::ReadyToJoin => $readyCount > 1 ? "{$readyCount} Ready" : '1 Ready',
            CrewReliefStatus::ReliefOnboard => 'Relief Onboard',
            CrewReliefStatus::Mobilising => '1 Mobilising',
            CrewReliefStatus::AssignmentCreated => 'Assignment Created',
            CrewReliefStatus::ReliefPlanned => 'Relief Planned',
            CrewReliefStatus::NoRelief => 'No Relief',
        };

        return [
            'summary' => $summary,
            'status' => $worst->value,
            'status_label' => $worst->label(),
            'ready_count' => $readyCount,
            'mobilisation_readiness_label' => $mobilisationLabel,
            'reliefs' => $reliefs,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     status: string,
     *     required: int,
     *     onboard: int,
     *     current_gap: int,
     *     future_gap: int,
     *     next_gap_date: string|null,
     *     overlap_excess: int,
     *     projected_shortfall_days: int,
     *     reason: string
     * }
     */
    private function summarizeVessel(int $vesselId, array $rows, array $snapshot): array
    {
        if ($rows === []) {
            $status = VesselManningHealthStatus::NotConfigured;

            return [
                'status' => $status->value,
                'required' => 0,
                'onboard' => 0,
                'current_gap' => 0,
                'future_gap' => 0,
                'next_gap_date' => null,
                'overlap_excess' => 0,
                'projected_shortfall_days' => 0,
                'reason' => 'Configure required crew by rank before vessel manning health can be evaluated.',
            ];
        }

        $required = 0;
        $onboard = 0;
        $currentGap = 0;
        $futureGap = 0;
        $overlapExcess = 0;
        $hasCritical = false;
        $hasAtRisk = false;
        $nextGapDate = null;
        $atRiskReasons = [];
        $criticalReasons = [];

        foreach ($rows as $row) {
            $required += (int) $row['required'];
            $onboard += (int) $row['onboard'];
            $currentGap += (int) $row['current_gap'];
            $futureGap += (int) $row['future_gap'];
            $overlapExcess += (int) $row['overlap_excess'];

            if ($row['status'] === VesselManningHealthStatus::Critical->value) {
                $hasCritical = true;
                $criticalReasons[] = sprintf(
                    '%s currently %d / %d',
                    (string) $row['rank_name'],
                    (int) $row['onboard'],
                    (int) $row['required'],
                );
            }

            if ($row['status'] === VesselManningHealthStatus::AtRisk->value) {
                $hasAtRisk = true;
                $date = is_string($row['next_gap_date'] ?? null) ? (string) $row['next_gap_date'] : null;

                if ($date !== null && ($nextGapDate === null || $date < $nextGapDate)) {
                    $nextGapDate = $date;
                }

                $atRiskReasons[] = (string) $row['reason'];
            }
        }

        $shortfallDays = 0;

        foreach ($snapshot['projection']['items'] as $item) {
            if ((int) $item['vessel_id'] !== $vesselId) {
                continue;
            }

            foreach ($item['periods'] as $period) {
                if ((int) $period['gap'] <= 0) {
                    continue;
                }

                $from = CarbonImmutable::parse((string) $period['from']);
                $to = CarbonImmutable::parse((string) $period['to']);
                $shortfallDays += (int) $from->diffInDays($to) + 1;
            }
        }

        if ($hasCritical) {
            $status = VesselManningHealthStatus::Critical;
            $reason = $currentGap === 1
                ? '1 position uncovered'
                : "{$currentGap} positions uncovered";

            if ($criticalReasons !== []) {
                $reason .= '. '.$criticalReasons[0];
            }
        } elseif ($hasAtRisk) {
            $status = VesselManningHealthStatus::AtRisk;
            $reason = $futureGap === 1
                ? '1 position projected short'
                : "{$futureGap} positions projected short";

            if ($nextGapDate !== null) {
                $reason .= ' from '.$this->formatDate($nextGapDate);
            }
        } else {
            $status = VesselManningHealthStatus::Healthy;
            $reason = 'No projected manning gaps in the next '.self::HORIZON_DAYS.' days.';
        }

        return [
            'status' => $status->value,
            'required' => $required,
            'onboard' => $onboard,
            'current_gap' => $currentGap,
            'future_gap' => $futureGap,
            'next_gap_date' => $hasCritical ? null : $nextGapDate,
            'overlap_excess' => $overlapExcess,
            'projected_shortfall_days' => $shortfallDays,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function compactReason(VesselManningHealthStatus $status, array $summary): string
    {
        return match ($status) {
            VesselManningHealthStatus::NotConfigured => 'Manning not configured',
            VesselManningHealthStatus::Critical => $summary['current_gap'] === 1
                ? 'Critical · 1 gap'
                : 'Critical · '.$summary['current_gap'].' gaps',
            VesselManningHealthStatus::AtRisk => $summary['next_gap_date'] !== null
                ? 'At Risk · '.$this->formatDate((string) $summary['next_gap_date'])
                : 'At Risk',
            VesselManningHealthStatus::Healthy => 'Healthy',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $signoffs
     * @param  array<string, mixed>  $reliefContext
     */
    private function rankReason(
        VesselManningHealthStatus $status,
        string $rankName,
        int $required,
        int $onboard,
        int $projected,
        ?string $nextGapDate,
        array $signoffs,
        array $reliefContext,
        int $overlapExcess,
    ): string {
        if ($status === VesselManningHealthStatus::Critical) {
            $gap = $required - $onboard;

            return $gap === 1
                ? '1 Current Gap'
                : "{$gap} Current Gap";
        }

        if ($status === VesselManningHealthStatus::AtRisk) {
            $parts = [sprintf('%s becomes %d / %d', $rankName, $projected, $required)];

            $soonest = collect($signoffs)
                ->filter(fn (array $row): bool => is_string($row['planned_signoff_at'] ?? null))
                ->sortBy('planned_signoff_at')
                ->first();

            if (is_array($soonest) && is_string($soonest['planned_signoff_at'])) {
                $who = is_string($soonest['employee_name'] ?? null) ? (string) $soonest['employee_name'].' signs off ' : 'Planned sign-off ';
                $parts[] = $who.$this->formatDate($soonest['planned_signoff_at']);
            } elseif ($nextGapDate !== null) {
                $parts[] = 'Gap from '.$this->formatDate($nextGapDate);
            }

            if (($reliefContext['status'] ?? null) === CrewReliefStatus::NoRelief->value) {
                $parts[] = 'No Relief';
            } elseif (($reliefContext['status'] ?? null) === CrewReliefStatus::ReadyToJoin->value) {
                $parts[] = 'Relief Ready to Join';
            } elseif (($reliefContext['status'] ?? null) === CrewReliefStatus::Mobilising->value) {
                $parts[] = 'Relief not ready yet';
            }

            return implode('. ', $parts);
        }

        $reason = 'Covered';

        if ($overlapExcess > 0) {
            $reason .= sprintf('. +%d temporary overlap', $overlapExcess);
        }

        if (($reliefContext['status'] ?? null) === CrewReliefStatus::ReadyToJoin->value) {
            $reason .= '. Relief Ready to Join';
        }

        return $reason;
    }

    private function formatDate(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }

    private function vesselRankKey(int $vesselId, int $rankId): string
    {
        return $vesselId.'|'.$rankId;
    }

    private function canViewManning(?User $user): bool
    {
        return $user?->can('crew_operations.vessel_manning.view') ?? false;
    }

    private function canViewAssignmentDetails(?User $user): bool
    {
        return $user?->can('crew_operations.assignments.view') ?? false;
    }
}
