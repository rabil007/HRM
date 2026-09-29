<?php

namespace App\Support\Reports\CrewRelief;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\CrewAssignmentOverlapDetector;
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
        private readonly CrewAssignmentOverlapDetector $overlapDetector = new CrewAssignmentOverlapDetector,
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
     * @return array<string, string> map of CrewReliefPlanKey => conflict message
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

            if ($competing->isEmpty() && count($assignedPlans) <= 1) {
                continue;
            }

            foreach ($assignedPlans as $plan) {
                $planKey = CrewReliefPlanKey::for($plan);
                if ($planKey === null) {
                    continue;
                }

                $window = $this->overlapDetector->dateWindowForPlan($plan, $timezone);
                $planStart = $window['start'];
                $planEnd = $window['end'];

                if ($planStart === null) {
                    continue;
                }

                // 1. Detect internal conflicts with other relief plans in the same batch
                $internalConflictFound = false;
                foreach ($assignedPlans as $otherPlan) {
                    if ($otherPlan === $plan) {
                        continue;
                    }

                    $otherWindow = $this->overlapDetector->dateWindowForPlan($otherPlan, $timezone);
                    $otherStart = $otherWindow['start'];
                    $otherEnd = $otherWindow['end'];

                    if ($otherStart === null) {
                        continue;
                    }

                    if ($this->overlapDetector->windowsOverlap($planStart, $planEnd, $otherStart, $otherEnd)) {
                        $otherVessel = 'another vessel';
                        if ($otherPlan->relationLoaded('vessel') && $otherPlan->vessel !== null) {
                            $otherVessel = (string) $otherPlan->vessel->name;
                        } elseif ($otherPlan->relationLoaded('relievedAssignment')
                            && $otherPlan->relievedAssignment?->relationLoaded('vessel')
                            && $otherPlan->relievedAssignment->vessel !== null) {
                            $otherVessel = (string) $otherPlan->relievedAssignment->vessel->name;
                        }

                        $conflicts[$planKey] = "Employee has overlapping planned assignment on {$otherVessel}.";
                        $internalConflictFound = true;
                        break;
                    }
                }

                if ($internalConflictFound) {
                    continue;
                }

                // 2. Detect external conflicts with competing assignments
                foreach ($competing as $other) {
                    $otherVessel = $other->vessel?->name ?? 'another vessel';

                    if ($other->status === CrewAssignmentStatus::Active) {
                        if ($this->overlapDetector->overlapsActive($planStart, $planEnd, $other, $timezone)) {
                            $conflicts[$planKey] = "Employee has competing active assignment on {$otherVessel}.";
                            break;
                        }
                    } elseif ($other->status === CrewAssignmentStatus::Planned) {
                        if ($this->overlapDetector->overlapsPlanned($planStart, $planEnd, $other, $timezone)) {
                            $conflicts[$planKey] = "Employee has overlapping planned assignment on {$otherVessel}.";
                            break;
                        }
                    }
                }
            }
        }

        return $conflicts;
    }
}
