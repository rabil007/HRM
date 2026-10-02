<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StartPlanningMobilisation
{
    public function __construct(
        private readonly CrewAssignmentNumberGenerator $numbers = new CrewAssignmentNumberGenerator,
        private readonly CrewAssignmentConflictEvaluator $conflictEvaluator = new CrewAssignmentConflictEvaluator,
        private readonly CrewAssignmentInvariantGuard $invariants = new CrewAssignmentInvariantGuard,
    ) {}

    public function handle(
        int $companyId,
        CrewPlanningAssignment|int $planning,
        ?User $actor = null,
    ): CrewAssignment {
        $planningId = $planning instanceof CrewPlanningAssignment ? (int) $planning->id : (int) $planning;

        // Pre-read for early verification and idempotency check
        $preRead = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($planningId)
            ->first();

        if ($preRead === null) {
            throw CrewMovementException::make('Planning assignment could not be found.', 'planning_not_found');
        }

        if ($preRead->crew_assignment_id !== null) {
            $existing = CrewAssignmentAccess::findForCompany($companyId, (int) $preRead->crew_assignment_id, $actor);
            if ($existing !== null) {
                return $existing;
            }

            abort(404);
        }

        if ($preRead->employee_id === null) {
            throw CrewMovementException::make('Cannot start mobilisation for a vacant planning slot. Assign an employee first.', 'planning_vacant');
        }

        return DB::transaction(function () use ($companyId, $planningId, $preRead, $actor): CrewAssignment {
            // Canonical lock order: Employee -> CrewAssignment (relieved) -> CrewPlanningAssignment
            $employeeId = (int) $preRead->employee_id;
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->lockForUpdate()
                ->first();

            if ($employee === null) {
                throw CrewMovementException::make('The selected employee does not belong to this company.', 'employee_wrong_company');
            }

            if ($employee->status !== 'active') {
                throw CrewMovementException::make('Only active employees can receive a crew assignment.', 'employee_not_active');
            }

            if ($actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
                abort(404);
            }

            if ($preRead->relieves_crew_assignment_id !== null) {
                CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $preRead->relieves_crew_assignment_id)
                    ->lockForUpdate()
                    ->first();
            }

            $lockedPlanning = CrewPlanningAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey($planningId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: if already linked concurrently while waiting for lock, return existing visible assignment
            if ($lockedPlanning->crew_assignment_id !== null) {
                $existing = CrewAssignmentAccess::findForCompany($companyId, (int) $lockedPlanning->crew_assignment_id, $actor);
                if ($existing !== null) {
                    return $existing;
                }

                abort(404);
            }

            if ($lockedPlanning->employee_id === null) {
                throw CrewMovementException::make('Cannot start mobilisation for a vacant planning slot. Assign an employee first.', 'planning_vacant');
            }

            if ((int) $lockedPlanning->employee_id !== $employeeId) {
                $employeeId = (int) $lockedPlanning->employee_id;
                $employee = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey($employeeId)
                    ->lockForUpdate()
                    ->first();

                if ($employee === null) {
                    throw CrewMovementException::make('The selected employee does not belong to this company.', 'employee_wrong_company');
                }

                if ($employee->status !== 'active') {
                    throw CrewMovementException::make('Only active employees can receive a crew assignment.', 'employee_not_active');
                }

                if ($actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
                    abort(404);
                }
            }

            if ($lockedPlanning->vessel_id === null) {
                throw CrewMovementException::make('Planning assignment is missing a vessel.', 'planning_missing_vessel');
            }

            $vessel = Vessel::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $lockedPlanning->vessel_id)
                ->first();

            if ($vessel === null) {
                throw CrewMovementException::make('The planned vessel could not be found.', 'vessel_not_found');
            }

            if ($lockedPlanning->position_id === null) {
                throw CrewMovementException::make('Planning assignment is missing a position.', 'planning_missing_position');
            }

            $positionId = CrewPositionCatalog::resolveCrewAssignmentPositionId(
                $companyId,
                (int) $lockedPlanning->position_id,
            );

            if ($positionId === null) {
                throw CrewMovementException::make('The planned position is invalid.', 'position_invalid');
            }

            $arrival = $lockedPlanning->planned_arrival_date;
            $join = $lockedPlanning->planned_join_date;
            $leave = $lockedPlanning->planned_leave_date;

            if ($join === null) {
                throw CrewMovementException::make('Expected Vessel Join is required to start mobilisation.', 'planning_missing_join_date');
            }

            if ($leave === null) {
                throw CrewMovementException::make('Expected Sign-Off is required to start mobilisation.', 'planning_missing_leave_date');
            }

            $arrivalStr = $arrival?->toDateString();
            $joinStr = $join->toDateString();
            $leaveStr = $leave->toDateString();

            if ($arrivalStr !== null && $arrivalStr > $joinStr) {
                throw CrewMovementException::make('Arrival Date cannot be after Expected Vessel Join.', 'invalid_date_range');
            }

            if ($leaveStr < $joinStr) {
                throw CrewMovementException::make('Expected Sign-off cannot be before Expected Vessel Join.', 'invalid_date_range');
            }

            ValidatesCrewPlanningReliefLink::assertOrThrow($lockedPlanning, $actor);

            $clientId = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $lockedPlanning->vessel_id);

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $now = CarbonImmutable::now($timezone);

            $plannedArrivalAt = $arrivalStr !== null
                ? CarbonImmutable::parse($arrivalStr, $timezone)->startOfDay()
                : null;
            $plannedJoinAt = CarbonImmutable::parse($joinStr, $timezone)->startOfDay();
            $plannedSignoffAt = CarbonImmutable::parse($leaveStr, $timezone)->endOfDay();

            $conflictContext = new CrewAssignmentConflictContext(
                companyId: $companyId,
                employeeId: $employeeId,
                action: 'start',
                plannedJoinAt: $plannedJoinAt,
                plannedSignoffAt: $plannedSignoffAt,
                plannedArrivalAt: $plannedArrivalAt,
                operationalStartAt: $now,
                vesselId: (int) $lockedPlanning->vessel_id,
                positionId: $positionId,
                clientId: $clientId,
                relievesCrewAssignmentId: $lockedPlanning->relieves_crew_assignment_id !== null ? (int) $lockedPlanning->relieves_crew_assignment_id : null,
                currentAssignmentId: null,
                currentPlanningAssignmentId: (int) $lockedPlanning->id,
                actor: $actor,
            );

            $this->conflictEvaluator->assertNoBlockingConflicts($conflictContext, withLock: true);

            $assignmentNo = $this->numbers->next($companyId);
            $actorId = $actor?->id;

            $assignment = CrewAssignment::query()->create([
                'company_id' => $companyId,
                'assignment_no' => $assignmentNo,
                'employee_id' => $employeeId,
                'position_id' => $positionId,
                'client_id' => $clientId,
                'vessel_id' => (int) $lockedPlanning->vessel_id,
                'status' => CrewAssignmentStatus::Active,
                'started_at' => $now,
                'planned_arrival_at' => $plannedArrivalAt,
                'planned_join_at' => $plannedJoinAt,
                'planned_signoff_at' => $plannedSignoffAt,
                'relieves_crew_assignment_id' => $lockedPlanning->relieves_crew_assignment_id,
                'previous_assignment_id' => null,
                'source' => 'crew_planning',
                'remarks' => $lockedPlanning->notes,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $phase = CrewAssignmentPhase::query()->create([
                'company_id' => $companyId,
                'crew_assignment_id' => $assignment->id,
                'phase_code' => CrewPhaseCode::PreMobilisation,
                'sequence' => 1,
                'status' => CrewPhaseStatus::Active,
                'actual_start_at' => $now,
                'actual_end_at' => null,
                'remarks' => null,
                'started_by' => $actorId,
            ]);

            $assignment->update([
                'current_phase_id' => $phase->id,
                'updated_by' => $actorId,
            ]);

            $lockedPlanning->update([
                'crew_assignment_id' => $assignment->id,
            ]);

            $freshAssignment = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey($assignment->id)
                ->with(['currentPhase', 'phases', 'employee', 'vessel', 'position'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->invariants->assertValid($freshAssignment);

            activity()
                ->performedOn($freshAssignment)
                ->causedBy($actorId)
                ->withProperties([
                    'assignment_no' => $assignmentNo,
                    'company_id' => $companyId,
                    'source' => 'crew_planning',
                    'planning_assignment_id' => $lockedPlanning->id,
                ])
                ->log('mobilisation_started_from_planning');

            return $freshAssignment;
        });
    }
}
