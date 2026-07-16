<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use Carbon\CarbonImmutable;

final class CrewAssignmentStatusResolver
{
    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     current_phase: string|null,
     *     current_vessel: string|null,
     *     assignment_id: int|null,
     *     assignment_no: string|null,
     *     deployment_id: null,
     *     hint: null,
     *     since: string|null,
     *     days_in_phase: int|null,
     *     planned_next_date: string|null,
     *     warning: string|null,
     *     in_home_days: int|null,
     *     vessel_name: string|null,
     * }
     */
    public function forEmployee(Employee $employee, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today($employee->company?->timezone ?? config('app.timezone'));

        $assignment = CrewAssignment::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [CrewAssignmentStatus::Active, CrewAssignmentStatus::Draft])
            ->with(['currentPhase', 'vessel:id,name'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->first();

        if ($assignment === null) {
            $completed = CrewAssignment::query()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->where('status', CrewAssignmentStatus::Completed)
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->first();

            if ($completed !== null) {
                $inHomeDays = $completed->closed_at !== null
                    ? $completed->closed_at->startOfDay()->diffInDays($today)
                    : null;

                return $this->payload(
                    status: 'in_home',
                    label: $inHomeDays !== null ? "In home · {$inHomeDays}d" : 'In home',
                    assignment: $completed,
                    currentPhase: null,
                    currentVessel: null,
                    since: $completed->closed_at?->toIso8601String(),
                    daysInPhase: $inHomeDays,
                    plannedNext: null,
                    warning: null,
                    inHomeDays: $inHomeDays,
                );
            }

            return $this->available();
        }

        $phase = $assignment->currentPhase;
        $vesselName = $assignment->vessel?->name;

        if ($assignment->status === CrewAssignmentStatus::Draft) {
            return $this->fromPhase(
                $assignment,
                CrewPhaseCode::PreMobilisation,
                $phase,
                $vesselName,
                $today,
            );
        }

        if ($phase === null) {
            return $this->payload(
                status: 'movement_update_required',
                label: 'Needs update',
                assignment: $assignment,
                currentPhase: null,
                currentVessel: $vesselName,
                since: $assignment->started_at?->toIso8601String(),
                daysInPhase: null,
                plannedNext: $assignment->planned_join_at?->toDateString(),
                warning: 'Active assignment has no current phase.',
                inHomeDays: null,
            );
        }

        return $this->fromPhase($assignment, $phase->phase_code, $phase, $vesselName, $today);
    }

    /**
     * @param  Collection<int, Employee>|iterable<Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    public function forEmployees(iterable $employees, int $companyId, ?CarbonImmutable $today = null): array
    {
        $map = [];
        foreach ($employees as $employee) {
            if ((int) $employee->company_id !== $companyId) {
                continue;
            }
            $map[(int) $employee->id] = $this->forEmployee($employee, $today);
        }

        return $map;
    }

    private function fromPhase(
        CrewAssignment $assignment,
        CrewPhaseCode $code,
        ?CrewAssignmentPhase $phase,
        ?string $vesselName,
        CarbonImmutable $today,
    ): array {
        [$status, $label] = match ($code) {
            CrewPhaseCode::PreMobilisation => ['pre_mobilisation', 'Pre-mobilisation'],
            CrewPhaseCode::TravelIn => ['travel_in', 'Travel in'],
            CrewPhaseCode::JoinStandby => ['join_standby', 'Join standby'],
            CrewPhaseCode::Training => ['training', 'Training'],
            CrewPhaseCode::ReadyToJoin => ['ready_to_join', 'Ready to join'],
            CrewPhaseCode::OnVessel => ['on_vessel', 'On vessel'],
            CrewPhaseCode::DemobStandby => ['demob_standby', 'Demob standby'],
            CrewPhaseCode::HomeRedeploy => ['home_redeploy', 'Home / redeploy'],
        };

        $since = $phase?->actual_start_at ?? $assignment->started_at;
        $daysInPhase = $since !== null ? $since->copy()->startOfDay()->diffInDays($today) : null;

        $plannedNext = match ($code) {
            CrewPhaseCode::PreMobilisation, CrewPhaseCode::TravelIn, CrewPhaseCode::JoinStandby,
            CrewPhaseCode::Training, CrewPhaseCode::ReadyToJoin => $assignment->planned_join_at?->toDateString(),
            CrewPhaseCode::OnVessel => $assignment->planned_signoff_at?->toDateString(),
            CrewPhaseCode::DemobStandby => $assignment->planned_travel_at?->toDateString(),
            default => null,
        };

        $warning = null;
        if ($phase !== null && $assignment->status === CrewAssignmentStatus::Active
            && $phase->status !== CrewPhaseStatus::Active) {
            $warning = 'Current phase status is inconsistent.';
            $status = 'movement_update_required';
            $label = 'Needs update';
        }

        return $this->payload(
            status: $status,
            label: $label,
            assignment: $assignment,
            currentPhase: $code->value,
            currentVessel: $code === CrewPhaseCode::OnVessel ? $vesselName : null,
            since: $since?->toIso8601String(),
            daysInPhase: $daysInPhase,
            plannedNext: $plannedNext,
            warning: $warning,
            inHomeDays: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function available(): array
    {
        return [
            'status' => 'in_home',
            'label' => 'Available',
            'current_phase' => null,
            'current_vessel' => null,
            'assignment_id' => null,
            'assignment_no' => null,
            'deployment_id' => null,
            'hint' => null,
            'since' => null,
            'days_in_phase' => null,
            'planned_next_date' => null,
            'warning' => null,
            'in_home_days' => null,
            'vessel_name' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $status,
        string $label,
        CrewAssignment $assignment,
        ?string $currentPhase,
        ?string $currentVessel,
        ?string $since,
        ?int $daysInPhase,
        ?string $plannedNext,
        ?string $warning,
        ?int $inHomeDays,
    ): array {
        return [
            'status' => $status,
            'label' => $label,
            'current_phase' => $currentPhase,
            'current_vessel' => $currentVessel,
            'assignment_id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'deployment_id' => null,
            'hint' => null,
            'since' => $since,
            'days_in_phase' => $daysInPhase,
            'planned_next_date' => $plannedNext,
            'warning' => $warning,
            'in_home_days' => $inHomeDays,
            'vessel_name' => $assignment->vessel?->name,
        ];
    }
}
