<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\PayrollWorkAllocation;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 4: migrate legacy CrewAssignment(status=planned) into CrewPlanningAssignment.
 *
 * Explicit dry-run / apply only — never an automatic deploy-time schema migration.
 */
final class MigrateLegacyPlannedAssignments
{
    public const VERSION = 'phase4-v1';

    public const ACTIVITY = 'legacy_planned_migrated_to_crew_planning';

    public function __construct(
        private readonly SaveCrewPlanningAssignment $savePlanning = new SaveCrewPlanningAssignment,
    ) {}

    /**
     * @param  list<int>|null  $companyIds  null = all companies (caller must opt in)
     */
    public function inspect(?array $companyIds = null, ?int $assignmentId = null): LegacyPlannedMigrationReport
    {
        $candidates = $this->buildCandidates($companyIds, $assignmentId);

        return $this->makeReport(
            $candidates,
            applied: false,
            abortedDueToBlockers: false,
            companyIds: $companyIds,
            assignmentId: $assignmentId,
        );
    }

    /**
     * @param  list<int>|null  $companyIds
     */
    public function apply(?array $companyIds = null, ?int $assignmentId = null, ?User $actor = null): LegacyPlannedMigrationReport
    {
        $candidates = $this->buildCandidates($companyIds, $assignmentId);

        $blocked = array_filter(
            $candidates,
            fn (LegacyPlannedMigrationCandidate $c): bool => $c->migrationStatus === LegacyPlannedMigrationCandidate::STATUS_BLOCKED,
        );

        if ($blocked !== []) {
            return $this->makeReport(
                $candidates,
                applied: false,
                abortedDueToBlockers: true,
                companyIds: $companyIds,
                assignmentId: $assignmentId,
            );
        }

        $namedCreated = 0;
        $reused = 0;
        $retired = 0;

        foreach ($candidates as $index => $candidate) {
            if (! in_array($candidate->migrationStatus, [
                LegacyPlannedMigrationCandidate::STATUS_CONVERTIBLE,
                LegacyPlannedMigrationCandidate::STATUS_ALREADY_REPRESENTED,
            ], true)) {
                continue;
            }

            try {
                $result = $this->migrateOne($candidate, $actor);
                $candidates[$index] = $result['candidate'];

                if ($result['created']) {
                    $namedCreated++;
                }
                if ($result['reused']) {
                    $reused++;
                }
                if ($result['retired']) {
                    $retired++;
                }
            } catch (Throwable $exception) {
                $candidates[$index] = new LegacyPlannedMigrationCandidate(
                    assignmentId: $candidate->assignmentId,
                    assignmentNo: $candidate->assignmentNo,
                    companyId: $candidate->companyId,
                    employeeId: $candidate->employeeId,
                    employeeName: $candidate->employeeName,
                    vesselId: $candidate->vesselId,
                    positionId: $candidate->positionId,
                    vesselName: $candidate->vesselName,
                    positionName: $candidate->positionName,
                    plannedArrivalDate: $candidate->plannedArrivalDate,
                    plannedJoinDate: $candidate->plannedJoinDate,
                    plannedLeaveDate: $candidate->plannedLeaveDate,
                    relievesCrewAssignmentId: $candidate->relievesCrewAssignmentId,
                    remarks: $candidate->remarks,
                    linkedPlanningAssignmentId: $candidate->linkedPlanningAssignmentId,
                    migrationStatus: LegacyPlannedMigrationCandidate::STATUS_FAILED,
                    disposition: $candidate->disposition,
                    planningAssignmentId: $candidate->planningAssignmentId,
                    blockers: $candidate->blockers,
                    failureReason: $exception->getMessage(),
                );
            }
        }

        return $this->makeReport(
            $candidates,
            applied: true,
            abortedDueToBlockers: false,
            companyIds: $companyIds,
            assignmentId: $assignmentId,
            namedPlanningCreated: $namedCreated,
            existingPlanningReused: $reused,
            legacyPlannedRetired: $retired,
        );
    }

    /**
     * @param  list<int>|null  $companyIds
     * @return list<LegacyPlannedMigrationCandidate>
     */
    private function buildCandidates(?array $companyIds, ?int $assignmentId): array
    {
        $query = CrewAssignment::query()
            ->where('status', CrewAssignmentStatus::Planned)
            ->with([
                'employee:id,name,company_id,status,position_id',
                'vessel:id,name,company_id',
                'position:id,title,company_id,status',
                'planningAssignment',
                'phases',
                'currentPhase',
                'reliefAssignments:id,relieves_crew_assignment_id,company_id,status',
                'nextAssignments:id,previous_assignment_id,company_id,status',
                'timesheetPreparationLines:id,crew_assignment_id',
                'accommodationStays:id,crew_assignment_id',
                'corrections:id,crew_assignment_id',
                'company:id,timezone',
            ])
            ->orderBy('company_id')
            ->orderBy('id');

        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }

        if ($assignmentId !== null) {
            $query->whereKey($assignmentId);
        }

        return $query->get()
            ->map(fn (CrewAssignment $assignment): LegacyPlannedMigrationCandidate => $this->classify($assignment))
            ->values()
            ->all();
    }

    private function classify(CrewAssignment $assignment): LegacyPlannedMigrationCandidate
    {
        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $mapped = $this->mapDates($assignment, $timezone);
        $linked = $assignment->planningAssignment;

        $base = [
            'assignmentId' => (int) $assignment->id,
            'assignmentNo' => (string) $assignment->assignment_no,
            'companyId' => (int) $assignment->company_id,
            'employeeId' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
            'employeeName' => $assignment->employee?->name,
            'vesselId' => $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null,
            'vesselName' => $assignment->vessel?->name,
            'positionId' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
            'positionName' => $assignment->position?->title,
            'plannedArrivalDate' => $mapped['planned_arrival_date'],
            'plannedJoinDate' => $mapped['planned_join_date'],
            'plannedLeaveDate' => $mapped['planned_leave_date'],
            'relievesCrewAssignmentId' => $assignment->relieves_crew_assignment_id !== null
                ? (int) $assignment->relieves_crew_assignment_id
                : null,
            'remarks' => $assignment->remarks,
            'linkedPlanningAssignmentId' => $linked?->id !== null ? (int) $linked->id : null,
        ];

        $blockers = $this->detectBlockers($assignment, $mapped, $linked);

        if ($blockers !== []) {
            return new LegacyPlannedMigrationCandidate(
                ...$base,
                migrationStatus: LegacyPlannedMigrationCandidate::STATUS_BLOCKED,
                disposition: LegacyPlannedMigrationCandidate::DISPOSITION_NONE,
                planningAssignmentId: $linked?->id !== null ? (int) $linked->id : null,
                blockers: $blockers,
            );
        }

        if ($linked !== null && $linked->employee_id === null) {
            return new LegacyPlannedMigrationCandidate(
                ...$base,
                migrationStatus: LegacyPlannedMigrationCandidate::STATUS_CONVERTIBLE,
                disposition: LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_LINKED_VACANT,
                planningAssignmentId: (int) $linked->id,
                blockers: [],
            );
        }

        $equivalents = $this->findEquivalentUnlinkedPlanning($assignment, $mapped);

        if (count($equivalents) > 1) {
            return new LegacyPlannedMigrationCandidate(
                ...$base,
                migrationStatus: LegacyPlannedMigrationCandidate::STATUS_BLOCKED,
                disposition: LegacyPlannedMigrationCandidate::DISPOSITION_NONE,
                planningAssignmentId: null,
                blockers: ['ambiguous_equivalent_planning_matches'],
            );
        }

        if (count($equivalents) === 1) {
            return new LegacyPlannedMigrationCandidate(
                ...$base,
                migrationStatus: LegacyPlannedMigrationCandidate::STATUS_ALREADY_REPRESENTED,
                disposition: LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_EQUIVALENT,
                planningAssignmentId: (int) $equivalents[0]->id,
                blockers: [],
            );
        }

        return new LegacyPlannedMigrationCandidate(
            ...$base,
            migrationStatus: LegacyPlannedMigrationCandidate::STATUS_CONVERTIBLE,
            disposition: LegacyPlannedMigrationCandidate::DISPOSITION_CREATE,
            planningAssignmentId: null,
            blockers: [],
        );
    }

    /**
     * @param  array{planned_arrival_date: ?string, planned_join_date: ?string, planned_leave_date: ?string}  $mapped
     * @return list<string>
     */
    private function detectBlockers(
        CrewAssignment $assignment,
        array $mapped,
        ?CrewPlanningAssignment $linked,
    ): array {
        $blockers = [];
        $companyId = (int) $assignment->company_id;

        if ($assignment->employee_id === null) {
            $blockers[] = 'missing_employee';
        } else {
            $employee = $assignment->employee;
            if ($employee === null) {
                $blockers[] = 'missing_employee';
            } elseif ((int) $employee->company_id !== $companyId) {
                $blockers[] = 'cross_company_employee';
            } elseif ($employee->status !== 'active') {
                $blockers[] = 'inactive_employee';
            }
        }

        if ($assignment->vessel_id === null) {
            $blockers[] = 'missing_vessel';
        } else {
            $vessel = $assignment->vessel;
            if ($vessel === null) {
                $blockers[] = 'missing_vessel';
            } elseif ((int) $vessel->company_id !== $companyId) {
                $blockers[] = 'cross_company_vessel';
            }
        }

        if ($assignment->position_id === null) {
            $blockers[] = 'missing_position';
        } else {
            $position = $assignment->position;
            if ($position === null) {
                $blockers[] = 'missing_position';
            } elseif ((int) $position->company_id !== $companyId) {
                $blockers[] = 'cross_company_position';
            } elseif ($position->status !== null && $position->status !== 'active') {
                $blockers[] = 'inactive_position';
            }
        }

        if ($mapped['planned_join_date'] === null) {
            $blockers[] = 'missing_expected_join';
        }

        if ($mapped['planned_leave_date'] === null) {
            $blockers[] = 'missing_expected_sign_off';
        }

        if ($mapped['planned_join_date'] !== null && $mapped['planned_leave_date'] !== null
            && $mapped['planned_leave_date'] < $mapped['planned_join_date']) {
            $blockers[] = 'invalid_date_order_join_sign_off';
        }

        if ($mapped['planned_arrival_date'] !== null && $mapped['planned_join_date'] !== null
            && $mapped['planned_arrival_date'] > $mapped['planned_join_date']) {
            $blockers[] = 'invalid_date_order_arrival_join';
        }

        if ($assignment->relieves_crew_assignment_id !== null) {
            $relief = CrewAssignment::query()
                ->whereKey((int) $assignment->relieves_crew_assignment_id)
                ->first();

            if ($relief === null) {
                $blockers[] = 'missing_relief_assignment';
            } elseif ((int) $relief->company_id !== $companyId) {
                $blockers[] = 'cross_company_relief_assignment';
            }
        }

        if ($assignment->previous_assignment_id !== null) {
            $blockers[] = 'unsupported_previous_assignment_relationship';
        }

        if ($assignment->nextAssignments->isNotEmpty()) {
            $blockers[] = 'inbound_next_assignment_relationships';
        }

        if ($assignment->reliefAssignments->isNotEmpty()) {
            $blockers[] = 'inbound_relief_relationships';
        }

        if ($assignment->started_at !== null) {
            $blockers[] = 'unexpected_started_at';
        }

        $phases = $assignment->phases;
        foreach ($phases as $phase) {
            if ($phase->actual_start_at !== null || $phase->actual_end_at !== null) {
                $blockers[] = 'unexpected_phase_actuals';
                break;
            }
            if ($phase->status === CrewPhaseStatus::Active || $phase->status === CrewPhaseStatus::Completed) {
                $blockers[] = 'unexpected_operational_phase_status';
                break;
            }
            if ($phase->phase_code !== CrewPhaseCode::PreMobilisation) {
                $blockers[] = 'unexpected_non_p0_phase';
                break;
            }
        }

        if ($phases->count() > 1) {
            $blockers[] = 'unexpected_multiple_phases';
        }

        $phaseIds = $phases->pluck('id')->all();
        if ($phaseIds !== [] && EmployeeSeaService::query()->whereIn('crew_assignment_phase_id', $phaseIds)->exists()) {
            $blockers[] = 'unexpected_sea_service';
        }

        if ($assignment->timesheetPreparationLines->isNotEmpty()) {
            $blockers[] = 'unexpected_timesheet_preparation_lines';
        }

        if ($assignment->accommodationStays->isNotEmpty()) {
            $blockers[] = 'unexpected_accommodation_stays';
        }

        if ($assignment->corrections->isNotEmpty()) {
            $blockers[] = 'unexpected_movement_corrections';
        }

        if (PayrollWorkAllocation::query()->where('crew_assignment_id', $assignment->id)->exists()) {
            $blockers[] = 'unexpected_payroll_work_allocations';
        }

        if ($linked !== null) {
            if ((int) $linked->company_id !== $companyId) {
                $blockers[] = 'linked_planning_cross_company';
            }

            if ($linked->employee_id !== null && (int) $linked->employee_id !== (int) $assignment->employee_id) {
                $blockers[] = 'linked_planning_employee_conflict';
            }

            if ($linked->crew_assignment_id !== null && (int) $linked->crew_assignment_id !== (int) $assignment->id) {
                $blockers[] = 'linked_planning_points_elsewhere';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return array{planned_arrival_date: ?string, planned_join_date: ?string, planned_leave_date: ?string}
     */
    private function mapDates(CrewAssignment $assignment, string $timezone): array
    {
        return [
            'planned_arrival_date' => $assignment->planned_arrival_at
                ?->copy()
                ->timezone($timezone)
                ->toDateString(),
            'planned_join_date' => $assignment->planned_join_at
                ?->copy()
                ->timezone($timezone)
                ->toDateString(),
            'planned_leave_date' => $assignment->planned_signoff_at
                ?->copy()
                ->timezone($timezone)
                ->toDateString(),
        ];
    }

    /**
     * @param  array{planned_arrival_date: ?string, planned_join_date: ?string, planned_leave_date: ?string}  $mapped
     * @return list<CrewPlanningAssignment>
     */
    private function findEquivalentUnlinkedPlanning(CrewAssignment $assignment, array $mapped): array
    {
        $query = CrewPlanningAssignment::query()
            ->where('company_id', $assignment->company_id)
            ->whereNull('crew_assignment_id')
            ->where('employee_id', $assignment->employee_id)
            ->where('vessel_id', $assignment->vessel_id)
            ->where('position_id', $assignment->position_id)
            ->whereDate('planned_join_date', $mapped['planned_join_date'])
            ->whereDate('planned_leave_date', $mapped['planned_leave_date']);

        if ($mapped['planned_arrival_date'] === null) {
            $query->whereNull('planned_arrival_date');
        } else {
            $query->whereDate('planned_arrival_date', $mapped['planned_arrival_date']);
        }

        if ($assignment->relieves_crew_assignment_id === null) {
            $query->whereNull('relieves_crew_assignment_id');
        } else {
            $query->where('relieves_crew_assignment_id', $assignment->relieves_crew_assignment_id);
        }

        return $query->orderBy('id')->get()->all();
    }

    /**
     * @return array{candidate: LegacyPlannedMigrationCandidate, created: bool, reused: bool, retired: bool}
     */
    private function migrateOne(LegacyPlannedMigrationCandidate $candidate, ?User $actor): array
    {
        return DB::transaction(function () use ($candidate, $actor): array {
            $companyId = $candidate->companyId;
            $actorId = $actor?->id;

            if ($candidate->employeeId !== null) {
                Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey($candidate->employeeId)
                    ->lockForUpdate()
                    ->first();
            }

            $reliefIds = array_values(array_filter([
                $candidate->relievesCrewAssignmentId,
            ]));
            sort($reliefIds);
            foreach ($reliefIds as $reliefId) {
                CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey($reliefId)
                    ->lockForUpdate()
                    ->first();
            }

            /** @var CrewAssignment $assignment */
            $assignment = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey($candidate->assignmentId)
                ->lockForUpdate()
                ->with([
                    'employee',
                    'vessel',
                    'position',
                    'planningAssignment',
                    'phases',
                    'currentPhase',
                    'reliefAssignments',
                    'nextAssignments',
                    'timesheetPreparationLines',
                    'accommodationStays',
                    'corrections',
                ])
                ->firstOrFail();

            if ($assignment->status !== CrewAssignmentStatus::Planned) {
                throw new \RuntimeException('Assignment is no longer Planned; refusing to migrate.');
            }

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $mapped = $this->mapDates($assignment, $timezone);
            $linked = $assignment->planningAssignment;

            if ($linked !== null) {
                CrewPlanningAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey($linked->id)
                    ->lockForUpdate()
                    ->first();
                $linked = $linked->fresh();
            }

            $reclassified = $this->classify($assignment->fresh([
                'employee',
                'vessel',
                'position',
                'planningAssignment',
                'phases',
                'currentPhase',
                'reliefAssignments',
                'nextAssignments',
                'timesheetPreparationLines',
                'accommodationStays',
                'corrections',
            ]) ?? $assignment);

            if ($reclassified->migrationStatus === LegacyPlannedMigrationCandidate::STATUS_BLOCKED) {
                throw new \RuntimeException('Preflight blockers appeared before apply: '.implode(', ', $reclassified->blockers));
            }

            $created = false;
            $reused = false;
            $planningId = null;

            $planningAttributes = [
                'vessel_id' => $assignment->vessel_id,
                'position_id' => $assignment->position_id,
                'employee_id' => $assignment->employee_id,
                'planned_arrival_date' => $mapped['planned_arrival_date'],
                'planned_join_date' => $mapped['planned_join_date'],
                'planned_leave_date' => $mapped['planned_leave_date'],
                'relieves_crew_assignment_id' => $assignment->relieves_crew_assignment_id,
                'notes' => $assignment->remarks,
                'crew_assignment_id' => null,
            ];

            if ($reclassified->disposition === LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_LINKED_VACANT) {
                $planning = CrewPlanningAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $reclassified->planningAssignmentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($planning->employee_id !== null) {
                    throw new \RuntimeException('Linked planning row is no longer vacant.');
                }

                $planning->update($planningAttributes);
                $planningId = (int) $planning->id;
                $reused = true;

                $this->retireLegacyPlanned($assignment, $actorId);
            } elseif ($reclassified->disposition === LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_EQUIVALENT) {
                $planningId = (int) $reclassified->planningAssignmentId;
                CrewPlanningAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey($planningId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $reused = true;

                $this->retireLegacyPlanned($assignment, $actorId);
            } else {
                // Retire first so conflict checks do not see the legacy Planned reservation.
                $this->retireLegacyPlanned($assignment, $actorId);

                try {
                    // Admin migration path: skip actor visibility scope (command is not an end-user form).
                    $planning = $this->savePlanning->create($companyId, [
                        'vessel_id' => $planningAttributes['vessel_id'],
                        'position_id' => $planningAttributes['position_id'],
                        'employee_id' => $planningAttributes['employee_id'],
                        'planned_arrival_date' => $planningAttributes['planned_arrival_date'],
                        'planned_join_date' => $planningAttributes['planned_join_date'],
                        'planned_leave_date' => $planningAttributes['planned_leave_date'],
                        'relieves_crew_assignment_id' => $planningAttributes['relieves_crew_assignment_id'],
                        'notes' => $planningAttributes['notes'],
                    ], null);
                } catch (ValidationException $exception) {
                    throw new \RuntimeException(
                        'Failed to create CrewPlanningAssignment: '.$exception->validator->errors()->first(),
                        0,
                        $exception,
                    );
                }

                $planningId = (int) $planning->id;
                $created = true;
            }

            $migrationTimestamp = CarbonImmutable::now(CompanyTimezone::forCompanyId($companyId))->toIso8601String();
            $properties = [
                'legacy_crew_assignment_id' => $assignment->id,
                'legacy_assignment_no' => $assignment->assignment_no,
                'crew_planning_assignment_id' => $planningId,
                'company_id' => $companyId,
                'migration_timestamp' => $migrationTimestamp,
                'migration_command_version' => self::VERSION,
                'disposition' => $reclassified->disposition,
            ];

            activity()
                ->performedOn($assignment->fresh() ?? $assignment)
                ->causedBy($actor)
                ->withProperties($properties)
                ->log(self::ACTIVITY);

            $planningModel = CrewPlanningAssignment::query()->find($planningId);
            if ($planningModel !== null) {
                activity()
                    ->performedOn($planningModel)
                    ->causedBy($actor)
                    ->withProperties($properties)
                    ->log(self::ACTIVITY);
            }

            $migrated = new LegacyPlannedMigrationCandidate(
                assignmentId: $candidate->assignmentId,
                assignmentNo: $candidate->assignmentNo,
                companyId: $candidate->companyId,
                employeeId: $candidate->employeeId,
                employeeName: $candidate->employeeName,
                vesselId: $candidate->vesselId,
                vesselName: $candidate->vesselName,
                positionId: $candidate->positionId,
                positionName: $candidate->positionName,
                plannedArrivalDate: $mapped['planned_arrival_date'],
                plannedJoinDate: $mapped['planned_join_date'],
                plannedLeaveDate: $mapped['planned_leave_date'],
                relievesCrewAssignmentId: $candidate->relievesCrewAssignmentId,
                remarks: $candidate->remarks,
                linkedPlanningAssignmentId: $candidate->linkedPlanningAssignmentId,
                migrationStatus: LegacyPlannedMigrationCandidate::STATUS_MIGRATED,
                disposition: $reclassified->disposition,
                planningAssignmentId: $planningId,
                blockers: [],
            );

            return [
                'candidate' => $migrated,
                'created' => $created,
                'reused' => $reused,
                'retired' => true,
            ];
        });
    }

    private function retireLegacyPlanned(CrewAssignment $assignment, ?int $actorId): void
    {
        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $occurredAt = CarbonImmutable::now($timezone);

        $current = $assignment->currentPhase;
        if ($current !== null && $current->status !== CrewPhaseStatus::Cancelled) {
            $current->update([
                'status' => CrewPhaseStatus::Cancelled,
                'actual_end_at' => $occurredAt,
                'completed_by' => $actorId,
            ]);
        }

        // Preserve original remarks; migration provenance lives in activity log only.
        $assignment->update([
            'status' => CrewAssignmentStatus::Cancelled,
            'closed_at' => $occurredAt,
            'updated_by' => $actorId,
        ]);
    }

    /**
     * @param  list<LegacyPlannedMigrationCandidate>  $candidates
     * @param  list<int>|null  $companyIds
     */
    private function makeReport(
        array $candidates,
        bool $applied,
        bool $abortedDueToBlockers,
        ?array $companyIds,
        ?int $assignmentId = null,
        int $namedPlanningCreated = 0,
        int $existingPlanningReused = 0,
        int $legacyPlannedRetired = 0,
    ): LegacyPlannedMigrationReport {
        $remainingQuery = CrewAssignment::query()->where('status', CrewAssignmentStatus::Planned);

        if ($companyIds !== null) {
            $remainingQuery->whereIn('company_id', $companyIds);
        }

        if ($assignmentId !== null) {
            $remainingQuery->whereKey($assignmentId);
        }

        $scopedIds = array_values(array_unique(array_map(
            fn (LegacyPlannedMigrationCandidate $c): int => $c->companyId,
            $candidates,
        )));

        return new LegacyPlannedMigrationReport(
            candidates: $candidates,
            companyIds: $companyIds ?? $scopedIds,
            applied: $applied,
            abortedDueToBlockers: $abortedDueToBlockers,
            namedPlanningCreated: $namedPlanningCreated,
            existingPlanningReused: $existingPlanningReused,
            legacyPlannedRetired: $legacyPlannedRetired,
            remainingPlannedCount: (int) $remainingQuery->count(),
        );
    }
}
