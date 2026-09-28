<?php

namespace App\Support\Reports\CrewRelief;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\CrewTourOfDutyCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewReliefStatusResolver
{
    public const READINESS_READY = 'ready';

    public const READINESS_IN_PROGRESS = 'in_progress';

    public const READINESS_NOT_ASSIGNED = 'not_assigned';

    public const READINESS_AT_RISK = 'at_risk';

    public const READINESS_JOINED = 'joined';

    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
    ) {}

    public function daysUntilSignoff(
        string $signoffDate,
        string $timezone,
        ?CarbonInterface $asOf = null,
    ): int {
        $signoff = CarbonImmutable::parse($signoffDate, $timezone)->startOfDay();
        $today = CarbonImmutable::parse(
            ($asOf ?? now($timezone))->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        return (int) $today->diffInDays($signoff, false);
    }

    public function daysToSignoffLabel(?int $daysUntil): ?string
    {
        if ($daysUntil === null) {
            return null;
        }

        if ($daysUntil < 0) {
            $abs = abs($daysUntil);

            return $abs === 1 ? 'Overdue by 1 day' : "Overdue by {$abs} days";
        }

        if ($daysUntil === 0) {
            return 'Today';
        }

        if ($daysUntil === 1) {
            return '1 day';
        }

        return "{$daysUntil} days";
    }

    public function actualJoinedDate(CrewAssignment $assignment, string $timezone): ?string
    {
        $phase = $this->onVesselPhase($assignment);

        if ($phase?->actual_start_at === null) {
            return null;
        }

        return $phase->actual_start_at->copy()->timezone($timezone)->toDateString();
    }

    public function daysOnboard(CrewAssignment $assignment, string $timezone, ?CarbonInterface $asOf = null): ?int
    {
        $phase = $this->onVesselPhase($assignment);

        if ($phase?->actual_start_at === null) {
            return null;
        }

        return $this->calculator->daysOnboard(
            $phase->actual_start_at,
            $timezone,
            $asOf,
        );
    }

    private function onVesselPhase(CrewAssignment $assignment): ?CrewAssignmentPhase
    {
        if ($assignment->relationLoaded('currentPhase')
            && $assignment->currentPhase?->phase_code === CrewPhaseCode::OnVessel) {
            return $assignment->currentPhase;
        }

        if ($assignment->relationLoaded('phases')) {
            return $assignment->phases
                ->filter(fn (CrewAssignmentPhase $p): bool => $p->phase_code === CrewPhaseCode::OnVessel)
                ->sortByDesc(fn (CrewAssignmentPhase $p): int => (int) $p->sequence)
                ->first();
        }

        return $assignment->currentPhase;
    }

    /**
     * @return array{code: string, label: string}
     */
    public function resolveReadiness(
        bool $hasRelief,
        ?CrewPhaseCode $reliefPhaseCode,
        bool $hasConflict,
        bool $joinsLate,
        ?CrewAssignmentStatus $reliefAssignmentStatus = null,
    ): array {
        if (! $hasRelief) {
            return [
                'code' => self::READINESS_NOT_ASSIGNED,
                'label' => 'Not Assigned',
            ];
        }

        if ($hasConflict || $joinsLate) {
            return [
                'code' => self::READINESS_AT_RISK,
                'label' => 'At Risk',
            ];
        }

        if ($reliefPhaseCode === CrewPhaseCode::OnVessel) {
            return [
                'code' => self::READINESS_JOINED,
                'label' => 'Joined',
            ];
        }

        if ($reliefPhaseCode === CrewPhaseCode::ReadyToJoin) {
            return [
                'code' => self::READINESS_READY,
                'label' => 'Ready',
            ];
        }

        return [
            'code' => self::READINESS_IN_PROGRESS,
            'label' => 'In Progress',
        ];
    }

    /**
     * @return array{
     *     phase_code: string|null,
     *     phase_label: string|null,
     *     display_label: string,
     *     planned_join_date: string|null
     * }
     */
    public function resolveReliefStatusInfo(
        CrewAssignment|CrewPlanningAssignment|null $plan,
        string $timezone,
    ): array {
        if ($plan === null) {
            return [
                'phase_code' => null,
                'phase_label' => null,
                'display_label' => '—',
                'planned_join_date' => null,
            ];
        }

        $phase = null;
        $joinDate = null;

        if ($plan instanceof CrewAssignment) {
            $phase = $plan->relationLoaded('currentPhase') ? $plan->currentPhase : null;
            $joinDate = $plan->planned_join_at?->copy()->timezone($timezone)->toDateString();
        } else {
            $linked = $plan->relationLoaded('crewAssignment') ? $plan->crewAssignment : null;
            $phase = $linked?->relationLoaded('currentPhase') ? $linked->currentPhase : null;
            $joinDate = $plan->planned_join_date?->toDateString();
        }

        if ($phase !== null && $phase->phase_code !== null) {
            $codeStr = strtoupper($phase->phase_code->value);
            $phaseLabel = $phase->phase_code->label();

            return [
                'phase_code' => $phase->phase_code->value,
                'phase_label' => $phaseLabel,
                'display_label' => "{$codeStr} {$phaseLabel}",
                'planned_join_date' => $joinDate,
            ];
        }

        return [
            'phase_code' => null,
            'phase_label' => 'Planned',
            'display_label' => 'Planned',
            'planned_join_date' => $joinDate,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     assignment_no: string,
     *     status: string,
     *     status_label: string
     * }|null
     */
    public function resolveNextAssignment(CrewAssignment $assignment): ?array
    {
        if (! $assignment->relationLoaded('nextAssignments')) {
            return null;
        }

        $next = $assignment->nextAssignments
            ->filter(fn (CrewAssignment $linked): bool => (int) $linked->company_id === (int) $assignment->company_id
                && $linked->status !== CrewAssignmentStatus::Cancelled
                && ! $linked->isVoided())
            ->sortBy('id')
            ->first();

        if ($next === null) {
            return null;
        }

        return [
            'id' => (int) $next->id,
            'assignment_no' => (string) $next->assignment_no,
            'status' => $next->status->value,
            'status_label' => $next->status->label(),
        ];
    }

    /**
     * Detect assignment conflicts for relief plans in a single batch query.
     *
     * @param  Collection<int, CrewAssignment|CrewPlanningAssignment>  $plans
     * @return array<int, string> map of plan id => conflict message
     */
    public function detectBatchConflicts(
        int $companyId,
        Collection $plans,
        string $timezone,
    ): array {
        $employeeIds = [];
        $planByEmployee = [];
        $excludedAssignmentIds = [];

        foreach ($plans as $plan) {
            $employeeId = (int) ($plan->employee_id ?? 0);
            if ($employeeId <= 0) {
                continue;
            }

            $employeeIds[] = $employeeId;
            $planByEmployee[$employeeId][] = $plan;

            if ($plan instanceof CrewAssignment) {
                $excludedAssignmentIds[] = (int) $plan->id;
            } elseif ($plan->crew_assignment_id !== null) {
                $excludedAssignmentIds[] = (int) $plan->crew_assignment_id;
            }
        }

        $employeeIds = array_values(array_unique($employeeIds));
        if ($employeeIds === []) {
            return [];
        }

        $otherAssignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', [CrewAssignmentStatus::Active, CrewAssignmentStatus::Planned])
            ->when($excludedAssignmentIds !== [], fn ($q) => $q->whereNotIn('id', $excludedAssignmentIds))
            ->with(['currentPhase', 'vessel:id,name'])
            ->get()
            ->groupBy('employee_id');

        $conflicts = [];

        foreach ($employeeIds as $empId) {
            $assignedPlans = $planByEmployee[$empId] ?? [];
            $competing = $otherAssignments->get($empId, collect());

            if ($competing->isEmpty()) {
                continue;
            }

            foreach ($assignedPlans as $plan) {
                $planJoinDate = $plan instanceof CrewAssignment
                    ? $plan->planned_join_at?->copy()->timezone($timezone)->toDateString()
                    : $plan->planned_join_date?->toDateString();

                $planSignoffDate = $plan instanceof CrewAssignment
                    ? $plan->planned_signoff_at?->copy()->timezone($timezone)->toDateString()
                    : $plan->planned_signoff_date?->toDateString();

                foreach ($competing as $other) {
                    $otherVessel = $other->vessel?->name ?? 'another vessel';
                    $otherSignoff = $other->planned_signoff_at?->copy()->timezone($timezone)->toDateString();

                    if ($other->status === CrewAssignmentStatus::Active) {
                        // If active on another assignment with no signoff or signoff >= plan join date => conflict
                        if ($planJoinDate === null || $otherSignoff === null || $otherSignoff >= $planJoinDate) {
                            $conflicts[(int) $plan->id] = "Employee has competing active assignment on {$otherVessel}.";
                            break 2;
                        }
                    } elseif ($other->status === CrewAssignmentStatus::Planned) {
                        $otherJoin = $other->planned_join_at?->copy()->timezone($timezone)->toDateString();

                        if ($planJoinDate !== null && $otherJoin !== null) {
                            $planEnd = $planSignoffDate ?? $planJoinDate;
                            $otherEnd = $otherSignoff ?? $otherJoin;

                            if ($planJoinDate <= $otherEnd && $otherJoin <= $planEnd) {
                                $conflicts[(int) $plan->id] = "Employee has overlapping planned assignment on {$otherVessel}.";
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return $conflicts;
    }
}
