<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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
     *     has_active_assignment: bool,
     * }
     */
    public function forEmployee(Employee $employee, ?CarbonImmutable $today = null, bool $includeRestrictedFields = true): array
    {
        return $this->forEmployees([$employee], (int) $employee->company_id, $today, $includeRestrictedFields)[(int) $employee->id]
            ?? $this->available();
    }

    /**
     * @param  iterable<Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    public function forEmployees(iterable $employees, int $companyId, ?CarbonImmutable $today = null, bool $includeRestrictedFields = true): array
    {
        $employees = Collection::make($employees)
            ->filter(fn (Employee $employee): bool => (int) $employee->company_id === $companyId)
            ->values();

        if ($employees->isEmpty()) {
            return [];
        }

        return $this->forEmployeeIds(
            $companyId,
            $employees->map(fn (Employee $employee): int => (int) $employee->id)->all(),
            $today,
            $includeRestrictedFields,
        );
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, mixed>>
     */
    public function forEmployeeIds(int $companyId, array $employeeIds, ?CarbonImmutable $today = null, bool $includeRestrictedFields = true): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($employeeIds === []) {
            return [];
        }

        $today ??= $this->resolveToday($companyId);
        $openByEmployee = $this->preferredOpenAssignments($companyId, $employeeIds);
        $missingIds = array_values(array_diff($employeeIds, $openByEmployee->keys()->all()));
        $completedByEmployee = $missingIds === []
            ? collect()
            : $this->latestCompletedAssignments($companyId, $missingIds);

        $map = [];

        foreach ($employeeIds as $employeeId) {
            $open = $openByEmployee->get($employeeId);

            if ($open instanceof CrewAssignment) {
                $map[$employeeId] = $this->fromOpenAssignment($open, $today, $includeRestrictedFields);

                continue;
            }

            $completed = $completedByEmployee->get($employeeId);

            if ($completed instanceof CrewAssignment) {
                $map[$employeeId] = $this->fromCompletedAssignment($completed, $today, $includeRestrictedFields);

                continue;
            }

            $map[$employeeId] = $this->available();
        }

        return $map;
    }

    private function resolveToday(int $companyId): CarbonImmutable
    {
        $timezone = Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC');

        return CarbonImmutable::today($timezone);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, CrewAssignment>
     */
    private function preferredOpenAssignments(int $companyId, array $employeeIds): Collection
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', [CrewAssignmentStatus::Active, CrewAssignmentStatus::Draft])
            ->with(['currentPhase', 'vessel:id,name'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (CrewAssignment $assignment): int => (int) $assignment->employee_id)
            ->map(fn (Collection $group): CrewAssignment => $group->first());
    }

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, CrewAssignment>
     */
    private function latestCompletedAssignments(int $companyId, array $employeeIds): Collection
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('status', CrewAssignmentStatus::Completed)
            ->with('vessel:id,name')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (CrewAssignment $assignment): int => (int) $assignment->employee_id)
            ->map(fn (Collection $group): CrewAssignment => $group->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function fromOpenAssignment(CrewAssignment $assignment, CarbonImmutable $today, bool $includeRestrictedFields = true): array
    {
        $phase = $assignment->currentPhase;
        $vesselName = $assignment->vessel?->name;

        if ($assignment->status === CrewAssignmentStatus::Draft) {
            return $this->fromPhase(
                $assignment,
                CrewPhaseCode::PreMobilisation,
                $phase,
                $vesselName,
                $today,
                $includeRestrictedFields,
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
                hasActiveAssignment: true,
                includeRestrictedFields: $includeRestrictedFields,
            );
        }

        return $this->fromPhase($assignment, $phase->phase_code, $phase, $vesselName, $today, $includeRestrictedFields);
    }

    /**
     * @return array<string, mixed>
     */
    private function fromCompletedAssignment(CrewAssignment $completed, CarbonImmutable $today, bool $includeRestrictedFields = true): array
    {
        $inHomeDays = $completed->closed_at !== null
            ? $completed->closed_at->startOfDay()->diffInDays($today)
            : null;

        $label = 'In home';
        if ($includeRestrictedFields && $inHomeDays !== null) {
            $label = "In home · {$inHomeDays}d";
        }

        return $this->payload(
            status: 'in_home',
            label: $label,
            assignment: $completed,
            currentPhase: null,
            currentVessel: null,
            since: $completed->closed_at?->toIso8601String(),
            daysInPhase: $inHomeDays,
            plannedNext: null,
            warning: null,
            inHomeDays: $inHomeDays,
            hasActiveAssignment: false,
            includeRestrictedFields: $includeRestrictedFields,
        );
    }

    private function fromPhase(
        CrewAssignment $assignment,
        CrewPhaseCode $code,
        ?CrewAssignmentPhase $phase,
        ?string $vesselName,
        CarbonImmutable $today,
        bool $includeRestrictedFields = true,
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

        // Draft (P0 Pre-Mobilisation) is not counted as an active-assignment conflict
        // because the backend currently allows multiple drafts. Only truly Active
        // assignments should prevent accidental duplicate creation in the UI.
        $hasActiveAssignment = $assignment->status === CrewAssignmentStatus::Active;

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
            hasActiveAssignment: $hasActiveAssignment,
            includeRestrictedFields: $includeRestrictedFields,
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
            'has_active_assignment' => false,
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
        bool $hasActiveAssignment,
        bool $includeRestrictedFields = true,
    ): array {
        return [
            'status' => $status,
            'label' => $label,
            'current_phase' => $includeRestrictedFields ? $currentPhase : null,
            'current_vessel' => $includeRestrictedFields ? $currentVessel : null,
            'assignment_id' => $includeRestrictedFields ? $assignment->id : null,
            'assignment_no' => $includeRestrictedFields ? $assignment->assignment_no : null,
            'deployment_id' => null,
            'hint' => null,
            'since' => $includeRestrictedFields ? $since : null,
            'days_in_phase' => $includeRestrictedFields ? $daysInPhase : null,
            'planned_next_date' => $includeRestrictedFields ? $plannedNext : null,
            'warning' => $includeRestrictedFields ? $warning : null,
            'in_home_days' => $includeRestrictedFields ? $inHomeDays : null,
            'vessel_name' => $includeRestrictedFields ? $assignment->vessel?->name : null,
            'has_active_assignment' => $hasActiveAssignment,
        ];
    }
}
