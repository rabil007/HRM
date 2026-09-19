<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative create/update for Crew Planning rows.
 *
 * Form Requests provide early UX validation; this action re-locks the source
 * assignment and rechecks operational relief exclusivity inside the write transaction.
 */
final class SaveCrewPlanningAssignment
{
    public function __construct(
        private readonly CrewReliefReadinessResolver $reliefResolver = new CrewReliefReadinessResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(int $companyId, array $attributes, ?User $actor = null): CrewPlanningAssignment
    {
        return DB::transaction(function () use ($companyId, $attributes, $actor): CrewPlanningAssignment {
            $this->assertEmployeeIsActive($companyId, $attributes, $actor);
            $this->assertReliefConstraints($companyId, $attributes, null, $actor);

            return CrewPlanningAssignment::query()->create([
                ...$attributes,
                'company_id' => $companyId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        CrewPlanningAssignment $assignment,
        int $companyId,
        array $attributes,
        ?User $actor = null,
    ): CrewPlanningAssignment {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($assignment, $companyId, $attributes, $actor): CrewPlanningAssignment {
                    $hasIncomingRelief = array_key_exists('relieves_crew_assignment_id', $attributes);
                    $incomingRelievesId = $hasIncomingRelief && $attributes['relieves_crew_assignment_id'] !== null && $attributes['relieves_crew_assignment_id'] !== ''
                        ? (int) $attributes['relieves_crew_assignment_id']
                        : null;

                    $preReadRelievesId = CrewPlanningAssignment::query()
                        ->whereKey($assignment->id)
                        ->value('relieves_crew_assignment_id');
                    $preReadRelievesId = $preReadRelievesId !== null && $preReadRelievesId !== ''
                        ? (int) $preReadRelievesId
                        : null;

                    $idsToLock = array_values(array_filter(array_unique([
                        $preReadRelievesId,
                        $incomingRelievesId,
                    ])));
                    sort($idsToLock);

                    foreach ($idsToLock as $assignmentId) {
                        CrewAssignment::query()
                            ->where('company_id', $companyId)
                            ->whereKey($assignmentId)
                            ->lockForUpdate()
                            ->first();
                    }

                    $locked = CrewPlanningAssignment::query()
                        ->where('company_id', $companyId)
                        ->whereKey($assignment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedRelievesId = $locked->relieves_crew_assignment_id !== null && $locked->relieves_crew_assignment_id !== ''
                        ? (int) $locked->relieves_crew_assignment_id
                        : null;

                    if ($lockedRelievesId !== $preReadRelievesId) {
                        throw new PlanningConcurrencyConflictException('The planning assignment was modified concurrently.');
                    }

                    if ($locked->crew_assignment_id !== null) {
                        throw ValidationException::withMessages([
                            'error' => 'This planning bar is controlled by Crew Assignments. Update the linked crew assignment instead.',
                        ]);
                    }

                    $merged = [
                        'relieves_crew_assignment_id' => array_key_exists('relieves_crew_assignment_id', $attributes)
                            ? $attributes['relieves_crew_assignment_id']
                            : $locked->relieves_crew_assignment_id,
                        'vessel_id' => array_key_exists('vessel_id', $attributes)
                            ? $attributes['vessel_id']
                            : $locked->vessel_id,
                        'rank_id' => array_key_exists('rank_id', $attributes)
                            ? $attributes['rank_id']
                            : $locked->rank_id,
                        'employee_id' => array_key_exists('employee_id', $attributes)
                            ? $attributes['employee_id']
                            : $locked->employee_id,
                    ];

                    $this->assertEmployeeIsActive($companyId, $merged, $actor);
                    $this->assertReliefConstraints($companyId, $merged, (int) $locked->id, $actor);

                    $locked->update($attributes);

                    return $locked->fresh() ?? $locked;
                });
            } catch (PlanningConcurrencyConflictException $exception) {
                if ($attempt < $maxAttempts) {
                    usleep(10000);

                    continue;
                }

                throw ValidationException::withMessages([
                    'error' => 'The planning assignment was modified concurrently. Please refresh and try again.',
                ]);
            }
        }

        throw ValidationException::withMessages([
            'error' => 'The planning assignment was modified concurrently. Please refresh and try again.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertEmployeeIsActive(int $companyId, array $attributes, ?User $actor = null): void
    {
        $employeeId = $attributes['employee_id'] ?? null;

        if ($employeeId === null || $employeeId === '') {
            return;
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $employeeId)
            ->first(['id', 'status', 'department_id', 'user_id']);

        if ($employee === null || $employee->status !== 'active') {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee must be an active employee in this company.',
            ]);
        }

        if ($actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee could not be found.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertReliefConstraints(
        int $companyId,
        array $attributes,
        ?int $exceptPlanningId = null,
        ?User $actor = null,
    ): void {
        $relievesId = $attributes['relieves_crew_assignment_id'] ?? null;

        if ($relievesId === null || $relievesId === '') {
            return;
        }

        $relievesId = (int) $relievesId;

        $source = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($relievesId)
            ->with(['employee:id,rank_id', 'currentPhase'])
            ->lockForUpdate()
            ->first();

        if ($source === null) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'The selected assignment could not be found.',
            ]);
        }

        if ($actor !== null && $source->employee !== null
            && ! EmployeeVisibilityScope::canAccess($actor, $source->employee, $companyId)) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'The selected assignment could not be found.',
            ]);
        }

        if ($source->status !== CrewAssignmentStatus::Active
            || $source->currentPhase?->phase_code !== CrewPhaseCode::OnVessel
            || $source->currentPhase?->status !== CrewPhaseStatus::Active) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'Relief can only be planned for an active On Vessel assignment.',
            ]);
        }

        if ($source->vessel_id === null || $source->rank_id === null) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'The assignment being relieved must have a vessel and rank.',
            ]);
        }

        $vesselId = $attributes['vessel_id'] ?? null;
        $rankId = $attributes['rank_id'] ?? null;

        if ($vesselId === null || $vesselId === '') {
            throw ValidationException::withMessages([
                'vessel_id' => 'A vessel is required when planning relief.',
            ]);
        }

        if ((int) $vesselId !== (int) $source->vessel_id) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'The relief assignment must be on the same vessel as the assignment being relieved.',
            ]);
        }

        $sourceRankId = $source->rank_id ?? $source->employee?->rank_id;

        if ($rankId === null || $rankId === '') {
            throw ValidationException::withMessages([
                'rank_id' => 'A rank is required when planning relief.',
            ]);
        }

        if ($sourceRankId !== null && (int) $sourceRankId !== (int) $rankId) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'The relief assignment must be for the same rank as the assignment being relieved.',
            ]);
        }

        $employeeId = $attributes['employee_id'] ?? null;

        if ($employeeId !== null && $employeeId !== '') {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $employeeId)
                ->first(['id', 'status', 'rank_id', 'department_id', 'user_id']);

            if ($employee === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected relief employee could not be found.',
                ]);
            }

            if ($actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected relief employee could not be found.',
                ]);
            }

            if ($employee->status !== 'active') {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected relief employee must be active.',
                ]);
            }

            if ((int) $employee->rank_id !== (int) $rankId) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected relief employee must have the selected rank.',
                ]);
            }

            if ((int) $employee->id === (int) $source->employee_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The relief crew member cannot be the same person as the crew being relieved.',
                ]);
            }
        }

        if ($this->reliefResolver->hasActiveOperationalRelief($companyId, $relievesId, $exceptPlanningId)) {
            throw ValidationException::withMessages([
                'relieves_crew_assignment_id' => 'An active relief plan already exists for this assignment.',
            ]);
        }
    }
}
