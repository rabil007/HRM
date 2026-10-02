<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
            $attributes = self::normalizeAttributes($companyId, $attributes);
            $this->assertValidAttributes($companyId, $attributes, null, $actor);

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

                    $attributes = self::normalizeAttributes($companyId, $attributes);

                    $merged = [
                        'relieves_crew_assignment_id' => array_key_exists('relieves_crew_assignment_id', $attributes)
                            ? $attributes['relieves_crew_assignment_id']
                            : $locked->relieves_crew_assignment_id,
                        'vessel_id' => array_key_exists('vessel_id', $attributes)
                            ? $attributes['vessel_id']
                            : $locked->vessel_id,
                        'position_id' => $attributes['position_id'] ?? $locked->position_id,
                        'employee_id' => array_key_exists('employee_id', $attributes)
                            ? $attributes['employee_id']
                            : $locked->employee_id,
                        'planned_arrival_date' => array_key_exists('planned_arrival_date', $attributes)
                            ? $attributes['planned_arrival_date']
                            : $locked->planned_arrival_date?->toDateString(),
                        'planned_join_date' => $attributes['planned_join_date'] ?? $locked->planned_join_date?->toDateString(),
                        'planned_leave_date' => array_key_exists('planned_leave_date', $attributes)
                            ? $attributes['planned_leave_date']
                            : $locked->planned_leave_date?->toDateString(),
                        'notes' => array_key_exists('notes', $attributes)
                            ? $attributes['notes']
                            : $locked->notes,
                    ];

                    $this->assertValidAttributes($companyId, $merged, (int) $locked->id, $actor);

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
    private function assertValidAttributes(
        int $companyId,
        array $attributes,
        ?int $exceptPlanningId = null,
        ?User $actor = null,
    ): void {
        $arrival = $attributes['planned_arrival_date'] ?? null;
        $join = $attributes['planned_join_date'] ?? null;
        $leave = $attributes['planned_leave_date'] ?? null;

        if ($arrival !== null && $arrival !== '' && $join !== null && $join !== '') {
            if ($arrival > $join) {
                throw ValidationException::withMessages([
                    'planned_arrival_date' => 'Arrival Date cannot be after Expected Vessel Join.',
                ]);
            }
        }

        if ($join !== null && $join !== '' && $leave !== null && $leave !== '') {
            if ($leave < $join) {
                throw ValidationException::withMessages([
                    'planned_leave_date' => 'Expected Sign-off cannot be before Expected Vessel Join.',
                ]);
            }
        }

        $this->assertReliefConstraints($companyId, $attributes, $exceptPlanningId, $actor);

        $employeeId = $attributes['employee_id'] ?? null;
        if ($employeeId !== null && $employeeId !== '') {
            $employeeId = (int) $employeeId;

            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->lockForUpdate()
                ->first();

            if ($employee === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected employee does not belong to this company.',
                ]);
            }

            if ($employee->status !== 'active') {
                throw ValidationException::withMessages([
                    'employee_id' => 'Only active employees can receive a crew assignment.',
                ]);
            }

            if ($actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected employee does not belong to this company.',
                ]);
            }

            if ($join !== null && $join !== '' && $leave !== null && $leave !== '') {
                $timezone = CompanyTimezone::forCompanyId($companyId);
                $conflictContext = new CrewAssignmentConflictContext(
                    companyId: $companyId,
                    employeeId: $employeeId,
                    action: 'plan',
                    plannedJoinAt: CarbonImmutable::parse($join, $timezone)->startOfDay(),
                    plannedSignoffAt: CarbonImmutable::parse($leave, $timezone)->endOfDay(),
                    plannedArrivalAt: $arrival !== null && $arrival !== '' ? CarbonImmutable::parse($arrival, $timezone)->startOfDay() : null,
                    vesselId: ! empty($attributes['vessel_id']) ? (int) $attributes['vessel_id'] : null,
                    positionId: ! empty($attributes['position_id']) ? (int) $attributes['position_id'] : null,
                    relievesCrewAssignmentId: ! empty($attributes['relieves_crew_assignment_id']) ? (int) $attributes['relieves_crew_assignment_id'] : null,
                    currentAssignmentId: null,
                    currentPlanningAssignmentId: $exceptPlanningId,
                    actor: $actor,
                );

                (new CrewAssignmentConflictEvaluator)->assertNoBlockingConflicts($conflictContext, withLock: true);
            }
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

        CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $relievesId)
            ->lockForUpdate()
            ->first();

        $existing = $exceptPlanningId !== null
            ? CrewPlanningAssignment::query()->whereKey($exceptPlanningId)->first()
            : null;

        $validator = Validator::make([], []);

        ValidatesCrewPlanningReliefLink::validate($validator, [
            'company_id' => $companyId,
            'relieves_crew_assignment_id' => $relievesId,
            'vessel_id' => $attributes['vessel_id'] ?? null,
            'position_id' => $attributes['position_id'] ?? null,
            'employee_id' => $attributes['employee_id'] ?? null,
        ], $existing, $actor);

        if ($validator->errors()->isNotEmpty()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function normalizeAttributes(int $companyId, array $attributes): array
    {
        if (array_key_exists('employee_id', $attributes)) {
            $attributes['employee_id'] = $attributes['employee_id'] !== null && $attributes['employee_id'] !== ''
                ? (int) $attributes['employee_id']
                : null;
        }

        if (array_key_exists('relieves_crew_assignment_id', $attributes)) {
            $attributes['relieves_crew_assignment_id'] = $attributes['relieves_crew_assignment_id'] !== null && $attributes['relieves_crew_assignment_id'] !== ''
                ? (int) $attributes['relieves_crew_assignment_id']
                : null;
        }

        if (array_key_exists('planned_arrival_date', $attributes)) {
            $attributes['planned_arrival_date'] = $attributes['planned_arrival_date'] !== null && $attributes['planned_arrival_date'] !== ''
                ? $attributes['planned_arrival_date']
                : null;
        }

        if (array_key_exists('notes', $attributes)) {
            $attributes['notes'] = $attributes['notes'] !== null && $attributes['notes'] !== ''
                ? (string) $attributes['notes']
                : null;
        }

        return $attributes;
    }
}
