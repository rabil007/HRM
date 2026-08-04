<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Transactional crew movement engine.
 *
 * CrewAssignment is the single source of truth for crew movement.
 * Completed P4 phases sync EmployeeSeaService within the same transaction.
 * Eligible assignments sync CrewPlanningAssignment after each movement action.
 */
final class CrewMovementService
{
    public function __construct(
        private CrewAssignmentInvariantGuard $invariants,
        private CrewAssignmentNumberGenerator $numbers,
        private SyncSeaServiceFromCrewAssignment $seaServiceSync,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
        private CrewMovementMasterDataGuard $masters,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(
        int $companyId,
        int $employeeId,
        array $attributes = [],
        ?int $actorId = null,
    ): CrewAssignment {
        return DB::transaction(function () use ($companyId, $employeeId, $attributes, $actorId): CrewAssignment {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->lockForUpdate()
                ->first();

            if ($employee === null) {
                throw CrewMovementException::make(
                    'Employee not found in this company.',
                    'employee_not_found',
                );
            }

            if ($employee->status !== 'active') {
                throw CrewMovementException::make(
                    'Only active employees can receive a draft crew assignment.',
                    'employee_not_active',
                );
            }

            $this->assertNoActiveAssignment($companyId, $employeeId);

            $assignmentNo = $this->numbers->next($companyId);

            $assignment = CrewAssignment::query()->create([
                'company_id' => $companyId,
                'assignment_no' => $assignmentNo,
                'employee_id' => $employeeId,
                'rank_id' => $attributes['rank_id'] ?? $employee->rank_id,
                'client_id' => $attributes['client_id'] ?? null,
                'vessel_id' => $attributes['vessel_id'] ?? null,
                'company_visa_type_id' => $attributes['company_visa_type_id'] ?? null,
                'status' => CrewAssignmentStatus::Draft,
                'planned_join_at' => $attributes['planned_join_at'] ?? null,
                'planned_signoff_at' => $attributes['planned_signoff_at'] ?? null,
                'planned_travel_at' => $attributes['planned_travel_at'] ?? null,
                'previous_assignment_id' => $attributes['previous_assignment_id'] ?? null,
                'source' => $attributes['source'] ?? 'manual',
                'remarks' => $attributes['remarks'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $phase = CrewAssignmentPhase::query()->create([
                'company_id' => $companyId,
                'crew_assignment_id' => $assignment->id,
                'phase_code' => CrewPhaseCode::PreMobilisation,
                'sequence' => 1,
                'status' => CrewPhaseStatus::Planned,
                'remarks' => null,
            ]);

            $assignment->update([
                'current_phase_id' => $phase->id,
                'updated_by' => $actorId,
            ]);

            $assignment = $this->reloadLocked($companyId, $assignment->id);
            $this->invariants->assertValid($assignment);

            return $assignment;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function perform(
        int $companyId,
        int $assignmentId,
        CrewMovementAction $action,
        array $payload = [],
        ?int $actorId = null,
    ): CrewAssignment {
        return DB::transaction(function () use ($companyId, $assignmentId, $action, $payload, $actorId): CrewAssignment {
            $assignment = $this->reloadLocked($companyId, $assignmentId);
            $this->invariants->assertValid($assignment);

            $result = match ($action) {
                CrewMovementAction::ApproveMobilisation => $this->approveMobilisation($assignment, $payload, $actorId),
                CrewMovementAction::RecordArrival => $this->recordArrival($assignment, $payload, $actorId),
                CrewMovementAction::StartJoinStandby => $this->startJoinStandby($assignment, $payload, $actorId),
                CrewMovementAction::SendToTraining => $this->sendToTraining($assignment, $payload, $actorId),
                CrewMovementAction::CompleteTraining => $this->completeTraining($assignment, $payload, $actorId),
                CrewMovementAction::MarkReady => $this->markReady($assignment, $payload, $actorId),
                CrewMovementAction::JoinVessel => $this->joinVessel($assignment, $payload, $actorId),
                CrewMovementAction::PlanSignoff => $this->planSignoff($assignment, $payload, $actorId),
                CrewMovementAction::ConfirmDisembarkation => $this->confirmDisembarkation($assignment, $payload, $actorId),
                CrewMovementAction::StartDemobStandby => $this->confirmDisembarkation(
                    $assignment,
                    array_merge($payload, ['next_phase' => CrewPhaseCode::DemobStandby->value]),
                    $actorId,
                ),
                CrewMovementAction::TravelHome => $this->travelHome($assignment, $payload, $actorId),
                CrewMovementAction::TransferVessel => $this->transferVessel($assignment, $payload, $actorId),
                CrewMovementAction::Redeploy => $this->redeploy($assignment, $payload, $actorId),
                CrewMovementAction::CloseAssignment => $this->closeAssignment($assignment, $payload, $actorId),
                CrewMovementAction::CancelAssignment => $this->cancelAssignment($assignment, $payload, $actorId),
                CrewMovementAction::CorrectMovement => throw CrewMovementException::make(
                    sprintf('Action %s is not implemented in this phase.', $action->value),
                    'action_not_implemented',
                ),
            };

            $result = $this->reloadLocked($companyId, $result->id);
            $this->invariants->assertValid($result);
            $this->planningSync->sync($result);

            return $this->reloadLocked($companyId, $result->id);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function approveMobilisation(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Draft);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::PreMobilisation);
        $this->assertPhaseStatus($current, CrewPhaseStatus::Planned);

        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        $this->assertNoActiveAssignment($assignment->company_id, $assignment->employee_id, $assignment->id);

        $this->completePhase($current, $occurredAt, $occurredAt, $actorId);

        $next = $this->createPhase(
            $assignment,
            CrewPhaseCode::TravelIn,
            CrewPhaseStatus::Active,
            $occurredAt,
            null,
            $actorId,
        );

        $assignment->update([
            'status' => CrewAssignmentStatus::Active,
            'started_at' => $occurredAt,
            'current_phase_id' => $next->id,
            'updated_by' => $actorId,
        ]);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordArrival(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::TravelIn);
        $nextCode = $this->requireNextPhaseCode($payload, [CrewPhaseCode::JoinStandby, CrewPhaseCode::ReadyToJoin]);
        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        return $this->completeAndOpenNext($assignment, $current, $nextCode, $occurredAt, $actorId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function startJoinStandby(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment);
        if (! in_array($current->phase_code, [CrewPhaseCode::TravelIn, CrewPhaseCode::Training], true)) {
            throw CrewMovementException::make(
                'Join standby can only start from Travel In or Training.',
                'invalid_phase_for_action',
            );
        }

        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        return $this->completeAndOpenNext(
            $assignment,
            $current,
            CrewPhaseCode::JoinStandby,
            $occurredAt,
            $actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendToTraining(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::JoinStandby);
        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);

        $details = array_filter([
            'provider' => $payload['provider'] ?? null,
            'course' => $payload['course'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $next = $this->createPhase(
            $assignment,
            CrewPhaseCode::Training,
            CrewPhaseStatus::Active,
            $occurredAt,
            null,
            $actorId,
            [
                // Only persist planned training dates when the user explicitly entered them.
                // Do not copy the actual occurred_at timestamp into planned_start_at.
                'planned_start_at' => isset($payload['planned_start_at']) && filled($payload['planned_start_at'])
                    ? $this->parseTimestamp($assignment->company_id, (string) $payload['planned_start_at'])
                    : null,
                'planned_end_at' => isset($payload['planned_end_at']) && filled($payload['planned_end_at'])
                    ? $this->parseTimestamp($assignment->company_id, (string) $payload['planned_end_at'])
                    : null,
                'details' => $details === [] ? null : $details,
                'remarks' => $payload['remarks'] ?? null,
            ],
        );

        $assignment->update([
            'current_phase_id' => $next->id,
            'updated_by' => $actorId,
        ]);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function completeTraining(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::Training);
        $nextCode = $this->requireNextPhaseCode($payload, [CrewPhaseCode::JoinStandby, CrewPhaseCode::ReadyToJoin]);
        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        return $this->completeAndOpenNext($assignment, $current, $nextCode, $occurredAt, $actorId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markReady(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment);
        if (! in_array($current->phase_code, [
            CrewPhaseCode::TravelIn,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::Training,
        ], true)) {
            throw CrewMovementException::make(
                'Mark ready is only allowed from Travel In, Join Standby, or Training.',
                'invalid_phase_for_action',
            );
        }

        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        return $this->completeAndOpenNext(
            $assignment,
            $current,
            CrewPhaseCode::ReadyToJoin,
            $occurredAt,
            $actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function joinVessel(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment);
        if (! in_array($current->phase_code, [CrewPhaseCode::JoinStandby, CrewPhaseCode::ReadyToJoin], true)) {
            throw CrewMovementException::make(
                'Join vessel is only allowed from Join Standby or Ready to Join.',
                'invalid_phase_for_action',
            );
        }

        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);
        $vesselId = (int) ($payload['vessel_id'] ?? 0);
        $rankId = (int) ($payload['rank_id'] ?? 0);

        if ($vesselId <= 0 || $rankId <= 0) {
            throw CrewMovementException::make(
                'Join vessel requires vessel_id and rank_id.',
                'join_vessel_missing_fields',
            );
        }

        $this->assertCompanyOwnedMaster($assignment->company_id, Vessel::class, $vesselId, 'vessel');
        $this->assertCompanyOwnedMaster($assignment->company_id, Rank::class, $rankId, 'rank');

        $clientId = isset($payload['client_id']) ? (int) $payload['client_id'] : null;
        $visaTypeId = isset($payload['company_visa_type_id']) ? (int) $payload['company_visa_type_id'] : null;

        if ($clientId) {
            $this->assertCompanyOwnedMaster($assignment->company_id, Client::class, $clientId, 'client');
        }
        if ($visaTypeId) {
            $this->assertCompanyOwnedMaster($assignment->company_id, CompanyVisaType::class, $visaTypeId, 'visa type');
        }

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);

        $next = $this->createPhase(
            $assignment,
            CrewPhaseCode::OnVessel,
            CrewPhaseStatus::Active,
            $occurredAt,
            null,
            $actorId,
            [
                'planned_end_at' => $payload['planned_signoff_at'] ?? null,
                'remarks' => $payload['remarks'] ?? null,
            ],
        );

        $assignment->update([
            'vessel_id' => $vesselId,
            'rank_id' => $rankId,
            'client_id' => $clientId ?? $assignment->client_id,
            'company_visa_type_id' => $visaTypeId ?? $assignment->company_visa_type_id,
            'planned_signoff_at' => $payload['planned_signoff_at'] ?? $assignment->planned_signoff_at,
            'current_phase_id' => $next->id,
            'updated_by' => $actorId,
            'remarks' => $payload['remarks'] ?? $assignment->remarks,
        ]);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function planSignoff(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::OnVessel);
        $this->assertPhaseStatus($current, CrewPhaseStatus::Active);

        if (empty($payload['planned_signoff_at'])) {
            throw CrewMovementException::make(
                'planned_signoff_at is required.',
                'planned_signoff_required',
            );
        }

        $planned = $this->parseTimestamp($assignment->company_id, (string) $payload['planned_signoff_at']);

        $current->update([
            'planned_end_at' => $planned,
        ]);

        $assignment->update([
            'planned_signoff_at' => $planned,
            'updated_by' => $actorId,
        ]);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirmDisembarkation(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::OnVessel);
        $nextCode = $this->requireNextPhaseCode($payload, [CrewPhaseCode::DemobStandby, CrewPhaseCode::HomeRedeploy]);
        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        return $this->completeAndOpenNext($assignment, $current, $nextCode, $occurredAt, $actorId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function travelHome(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::DemobStandby);
        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);

        $next = $this->createPhase(
            $assignment,
            CrewPhaseCode::HomeRedeploy,
            CrewPhaseStatus::Active,
            $occurredAt,
            null,
            $actorId,
        );

        $updates = [
            'current_phase_id' => $next->id,
            'updated_by' => $actorId,
        ];

        if (! empty($payload['planned_travel_at'])) {
            $updates['planned_travel_at'] = $this->parseTimestamp(
                $assignment->company_id,
                (string) $payload['planned_travel_at'],
            );
        }

        $assignment->update($updates);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function closeAssignment(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::HomeRedeploy);
        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);

        $assignment->update([
            'status' => CrewAssignmentStatus::Completed,
            'closed_at' => $occurredAt,
            'current_phase_id' => $current->id,
            'updated_by' => $actorId,
        ]);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function transferVessel(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment, CrewPhaseCode::OnVessel);
        $this->assertPhaseStatus($current, CrewPhaseStatus::Active);

        $this->lockEmployee($assignment->company_id, $assignment->employee_id);
        $this->lockAssignmentPhases($assignment);
        $this->assertNoActiveAssignment($assignment->company_id, $assignment->employee_id, $assignment->id);

        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);
        $destinationVesselId = (int) ($payload['vessel_id'] ?? 0);
        $destinationRankId = (int) ($payload['rank_id'] ?? 0);
        $destinationClientId = isset($payload['client_id']) ? (int) $payload['client_id'] : null;
        $destinationVisaTypeId = isset($payload['company_visa_type_id'])
            ? (int) $payload['company_visa_type_id']
            : null;

        if ($destinationVesselId <= 0 || $destinationRankId <= 0) {
            throw CrewMovementException::make(
                'Destination vessel and rank are required for vessel transfer.',
                'transfer_destination_required',
            );
        }

        if ($assignment->vessel_id !== null && $destinationVesselId === (int) $assignment->vessel_id) {
            throw CrewMovementException::make(
                'Destination vessel must differ from the current vessel.',
                'transfer_same_vessel',
            );
        }

        $this->assertCompanyOwnedMaster($assignment->company_id, Vessel::class, $destinationVesselId, 'vessel');
        $this->assertCompanyOwnedMaster($assignment->company_id, Rank::class, $destinationRankId, 'rank');

        if ($destinationClientId) {
            $this->assertCompanyOwnedMaster($assignment->company_id, Client::class, $destinationClientId, 'client');
        }

        if ($destinationVisaTypeId) {
            $this->assertCompanyOwnedMaster(
                $assignment->company_id,
                CompanyVisaType::class,
                $destinationVisaTypeId,
                'visa type',
            );
        }

        $sourceVesselId = $assignment->vessel_id;

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);
        $this->seaServiceSync->syncFromPhase($current->fresh());

        $assignment->update([
            'status' => CrewAssignmentStatus::Completed,
            'closed_at' => $occurredAt,
            'current_phase_id' => $current->id,
            'updated_by' => $actorId,
        ]);

        $source = $this->reloadLocked($assignment->company_id, $assignment->id);
        $this->invariants->assertValid($source);
        $this->planningSync->sync($source);

        $destination = $this->createLinkedAssignment(
            companyId: $assignment->company_id,
            employeeId: $assignment->employee_id,
            previousAssignmentId: $assignment->id,
            source: 'vessel_transfer',
            status: CrewAssignmentStatus::Active,
            startedAt: $occurredAt,
            plannedJoinAt: null,
            vesselId: $destinationVesselId,
            rankId: $destinationRankId,
            clientId: $destinationClientId ?? $assignment->client_id,
            companyVisaTypeId: $destinationVisaTypeId ?? $assignment->company_visa_type_id,
            plannedSignoffAt: isset($payload['planned_signoff_at'])
                ? $this->parseTimestamp($assignment->company_id, (string) $payload['planned_signoff_at'])
                : null,
            remarks: isset($payload['remarks']) ? (string) $payload['remarks'] : null,
            actorId: $actorId,
            startingPhase: CrewPhaseCode::OnVessel,
            phaseStatus: CrewPhaseStatus::Active,
            phaseActualStartAt: $occurredAt,
        );

        $this->invariants->assertValid($destination);
        $this->logVesselTransferred(
            $source,
            $destination,
            $sourceVesselId,
            $destinationVesselId,
            $occurredAt,
            $actorId,
        );

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function redeploy(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        $this->assertStatus($assignment, CrewAssignmentStatus::Active);
        $current = $this->requireCurrentPhase($assignment);
        $this->assertPhaseStatus($current, CrewPhaseStatus::Active);

        if (! in_array($current->phase_code, [CrewPhaseCode::DemobStandby, CrewPhaseCode::HomeRedeploy], true)) {
            throw CrewMovementException::make(
                'Redeploy is only available from P5 Demobilisation Standby or P6 Home / Redeployment.',
                'redeploy_invalid_phase',
            );
        }

        if (! CrewMovementTransitionMap::canStartLinkedAssignment($current->phase_code)) {
            throw CrewMovementException::make(
                'Redeploy cannot start a linked assignment from the current phase.',
                'redeploy_linked_start_blocked',
            );
        }

        $this->lockEmployee($assignment->company_id, $assignment->employee_id);
        $this->lockAssignmentPhases($assignment);
        $this->assertNoActiveAssignment($assignment->company_id, $assignment->employee_id, $assignment->id);

        $occurredAt = $this->requireOccurredAt($assignment->company_id, $payload);
        $startingPhase = $this->requireNextPhaseCode($payload, [
            CrewPhaseCode::PreMobilisation,
            CrewPhaseCode::TravelIn,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::ReadyToJoin,
            CrewPhaseCode::OnVessel,
        ]);

        $destinationVesselId = isset($payload['vessel_id']) ? (int) $payload['vessel_id'] : null;
        $destinationRankId = isset($payload['rank_id']) ? (int) $payload['rank_id'] : null;
        $destinationClientId = isset($payload['client_id']) ? (int) $payload['client_id'] : null;
        $destinationVisaTypeId = isset($payload['company_visa_type_id'])
            ? (int) $payload['company_visa_type_id']
            : null;

        if ($startingPhase === CrewPhaseCode::OnVessel) {
            if ($destinationVesselId === null || $destinationVesselId <= 0
                || $destinationRankId === null || $destinationRankId <= 0) {
                throw CrewMovementException::make(
                    'Destination vessel and rank are required when redeploying directly to On Vessel.',
                    'redeploy_p4_destination_required',
                );
            }
        }

        if ($destinationVesselId) {
            $this->assertCompanyOwnedMaster($assignment->company_id, Vessel::class, $destinationVesselId, 'vessel');
        }

        if ($destinationRankId) {
            $this->assertCompanyOwnedMaster($assignment->company_id, Rank::class, $destinationRankId, 'rank');
        }

        if ($destinationClientId) {
            $this->assertCompanyOwnedMaster($assignment->company_id, Client::class, $destinationClientId, 'client');
        }

        if ($destinationVisaTypeId) {
            $this->assertCompanyOwnedMaster(
                $assignment->company_id,
                CompanyVisaType::class,
                $destinationVisaTypeId,
                'visa type',
            );
        }

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);

        $assignment->update([
            'status' => CrewAssignmentStatus::Completed,
            'closed_at' => $occurredAt,
            'current_phase_id' => $current->id,
            'updated_by' => $actorId,
        ]);

        $source = $this->reloadLocked($assignment->company_id, $assignment->id);
        $this->invariants->assertValid($source);
        $this->planningSync->sync($source);

        $isDraftStart = $startingPhase === CrewPhaseCode::PreMobilisation;
        $destination = $this->createLinkedAssignment(
            companyId: $assignment->company_id,
            employeeId: $assignment->employee_id,
            previousAssignmentId: $assignment->id,
            source: 'redeployment',
            status: $isDraftStart ? CrewAssignmentStatus::Draft : CrewAssignmentStatus::Active,
            startedAt: $isDraftStart ? null : $occurredAt,
            plannedJoinAt: null,
            vesselId: $isDraftStart
                ? $destinationVesselId
                : ($destinationVesselId ?? $assignment->vessel_id),
            rankId: $isDraftStart
                ? $destinationRankId
                : ($destinationRankId ?? $assignment->rank_id),
            clientId: $isDraftStart
                ? $destinationClientId
                : ($destinationClientId ?? $assignment->client_id),
            companyVisaTypeId: $isDraftStart
                ? $destinationVisaTypeId
                : ($destinationVisaTypeId ?? $assignment->company_visa_type_id),
            plannedSignoffAt: $isDraftStart
                ? null
                : (isset($payload['planned_signoff_at']) && filled($payload['planned_signoff_at'])
                    ? $this->parseTimestamp($assignment->company_id, (string) $payload['planned_signoff_at'])
                    : null),
            remarks: isset($payload['remarks']) ? (string) $payload['remarks'] : null,
            actorId: $actorId,
            startingPhase: $startingPhase,
            phaseStatus: $isDraftStart ? CrewPhaseStatus::Planned : CrewPhaseStatus::Active,
            phaseActualStartAt: $isDraftStart ? null : $occurredAt,
        );

        $this->invariants->assertValid($destination);
        $this->logCrewRedeployed(
            $source,
            $destination,
            $startingPhase,
            $destinationVesselId ?? $assignment->vessel_id,
            $destinationClientId ?? $assignment->client_id,
            $occurredAt,
            $actorId,
        );

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelAssignment(CrewAssignment $assignment, array $payload, ?int $actorId): CrewAssignment
    {
        if ($assignment->status === CrewAssignmentStatus::Completed
            || $assignment->status === CrewAssignmentStatus::Cancelled) {
            throw CrewMovementException::make(
                'Closed assignments cannot be cancelled.',
                'assignment_already_closed',
            );
        }

        $current = $assignment->currentPhase;
        if ($current !== null && $current->phase_code === CrewPhaseCode::OnVessel
            && $current->status === CrewPhaseStatus::Active) {
            throw CrewMovementException::make(
                'Assignments on vessel cannot be cancelled directly.',
                'cancel_while_on_vessel',
            );
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw CrewMovementException::make(
                'A cancellation reason is required.',
                'cancel_reason_required',
            );
        }

        $occurredAt = isset($payload['occurred_at'])
            ? $this->parseTimestamp($assignment->company_id, (string) $payload['occurred_at'])
            : now($this->companyTimezone($assignment->company_id));

        if ($current !== null && $current->status !== CrewPhaseStatus::Cancelled) {
            $current->update([
                'status' => CrewPhaseStatus::Cancelled,
                'actual_end_at' => $occurredAt,
                'completed_by' => $actorId,
                'remarks' => trim(($current->remarks ? $current->remarks."\n" : '').'Cancelled: '.$reason),
            ]);
        }

        $assignment->update([
            'status' => CrewAssignmentStatus::Cancelled,
            'closed_at' => $occurredAt,
            'updated_by' => $actorId,
            'remarks' => trim(($assignment->remarks ? $assignment->remarks."\n" : '').'Cancelled: '.$reason),
        ]);

        return $assignment;
    }

    private function completeAndOpenNext(
        CrewAssignment $assignment,
        CrewAssignmentPhase $current,
        CrewPhaseCode $nextCode,
        CarbonInterface $occurredAt,
        ?int $actorId,
    ): CrewAssignment {
        if (! CrewMovementTransitionMap::canTransitionWithinAssignment($current->phase_code, $nextCode)) {
            throw CrewMovementException::make(
                sprintf(
                    'Transition from %s to %s is not allowed within the same assignment.',
                    $current->phase_code->value,
                    $nextCode->value,
                ),
                'invalid_transition',
            );
        }

        $this->completePhase($current, $current->actual_start_at ?? $occurredAt, $occurredAt, $actorId);

        if ($current->phase_code === CrewPhaseCode::OnVessel) {
            $this->seaServiceSync->syncFromPhase($current->fresh());
        }

        $next = $this->createPhase(
            $assignment,
            $nextCode,
            CrewPhaseStatus::Active,
            $occurredAt,
            null,
            $actorId,
        );

        $assignment->update([
            'current_phase_id' => $next->id,
            'updated_by' => $actorId,
        ]);

        return $assignment;
    }

    private function completePhase(
        CrewAssignmentPhase $phase,
        CarbonInterface $actualStart,
        CarbonInterface $actualEnd,
        ?int $actorId,
    ): void {
        $phase->update([
            'status' => CrewPhaseStatus::Completed,
            'actual_start_at' => $phase->actual_start_at ?? $actualStart,
            'actual_end_at' => $actualEnd,
            'completed_by' => $actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createPhase(
        CrewAssignment $assignment,
        CrewPhaseCode $code,
        CrewPhaseStatus $status,
        ?CarbonInterface $actualStart,
        ?CarbonInterface $actualEnd,
        ?int $actorId,
        array $extra = [],
    ): CrewAssignmentPhase {
        $sequence = (int) CrewAssignmentPhase::withTrashed()
            ->where('crew_assignment_id', $assignment->id)
            ->max('sequence');

        return CrewAssignmentPhase::query()->create([
            'company_id' => $assignment->company_id,
            'crew_assignment_id' => $assignment->id,
            'phase_code' => $code,
            'sequence' => $sequence + 1,
            'status' => $status,
            'planned_start_at' => $extra['planned_start_at'] ?? null,
            'planned_end_at' => $extra['planned_end_at'] ?? null,
            'actual_start_at' => $actualStart,
            'actual_end_at' => $actualEnd,
            'details' => $extra['details'] ?? null,
            'remarks' => $extra['remarks'] ?? null,
            'started_by' => $status === CrewPhaseStatus::Active ? $actorId : null,
            'completed_by' => $status === CrewPhaseStatus::Completed ? $actorId : null,
        ]);
    }

    private function createLinkedAssignment(
        int $companyId,
        int $employeeId,
        int $previousAssignmentId,
        string $source,
        CrewAssignmentStatus $status,
        ?CarbonInterface $startedAt,
        ?CarbonInterface $plannedJoinAt,
        ?int $vesselId,
        ?int $rankId,
        ?int $clientId,
        ?int $companyVisaTypeId,
        ?CarbonInterface $plannedSignoffAt,
        ?string $remarks,
        ?int $actorId,
        CrewPhaseCode $startingPhase,
        CrewPhaseStatus $phaseStatus,
        ?CarbonInterface $phaseActualStartAt,
    ): CrewAssignment {
        $assignmentNo = $this->numbers->next($companyId);

        $assignment = CrewAssignment::query()->create([
            'company_id' => $companyId,
            'assignment_no' => $assignmentNo,
            'employee_id' => $employeeId,
            'rank_id' => $rankId,
            'client_id' => $clientId,
            'vessel_id' => $vesselId,
            'company_visa_type_id' => $companyVisaTypeId,
            'status' => $status,
            'planned_join_at' => $plannedJoinAt,
            'planned_signoff_at' => $plannedSignoffAt,
            'started_at' => $startedAt,
            'previous_assignment_id' => $previousAssignmentId,
            'source' => $source,
            'remarks' => $remarks,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $phase = CrewAssignmentPhase::query()->create([
            'company_id' => $companyId,
            'crew_assignment_id' => $assignment->id,
            'phase_code' => $startingPhase,
            'sequence' => 1,
            'status' => $phaseStatus,
            'planned_end_at' => $plannedSignoffAt,
            'actual_start_at' => $phaseActualStartAt,
            'remarks' => $remarks,
            'started_by' => $phaseStatus === CrewPhaseStatus::Active ? $actorId : null,
        ]);

        $assignment->update([
            'current_phase_id' => $phase->id,
            'updated_by' => $actorId,
        ]);

        return $this->reloadLocked($companyId, $assignment->id);
    }

    private function lockEmployee(int $companyId, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->lockForUpdate()
            ->first();

        if ($employee === null) {
            throw CrewMovementException::make(
                'Employee not found in this company.',
                'employee_not_found',
            );
        }

        return $employee;
    }

    private function lockAssignmentPhases(CrewAssignment $assignment): void
    {
        CrewAssignmentPhase::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->lockForUpdate()
            ->get();
    }

    private function logVesselTransferred(
        CrewAssignment $source,
        CrewAssignment $destination,
        ?int $sourceVesselId,
        int $destinationVesselId,
        CarbonInterface $occurredAt,
        ?int $actorId,
    ): void {
        activity()
            ->performedOn($destination)
            ->causedBy($actorId)
            ->withProperties([
                'event' => 'crew_vessel_transferred',
                'company_id' => $source->company_id,
                'employee_id' => $source->employee_id,
                'source_assignment_id' => $source->id,
                'destination_assignment_id' => $destination->id,
                'source_vessel_id' => $sourceVesselId,
                'destination_vessel_id' => $destinationVesselId,
                'occurred_at' => $occurredAt->toDateTimeString(),
            ])
            ->tap(function ($activity) use ($source): void {
                $activity->company_id = $source->company_id;
            })
            ->log('Crew vessel transferred');
    }

    private function logCrewRedeployed(
        CrewAssignment $source,
        CrewAssignment $destination,
        CrewPhaseCode $startingPhase,
        ?int $destinationVesselId,
        ?int $destinationClientId,
        CarbonInterface $occurredAt,
        ?int $actorId,
    ): void {
        activity()
            ->performedOn($destination)
            ->causedBy($actorId)
            ->withProperties([
                'event' => 'crew_redeployed',
                'company_id' => $source->company_id,
                'employee_id' => $source->employee_id,
                'source_assignment_id' => $source->id,
                'destination_assignment_id' => $destination->id,
                'starting_phase' => $startingPhase->value,
                'destination_vessel_id' => $destinationVesselId,
                'destination_client_id' => $destinationClientId,
                'occurred_at' => $occurredAt->toDateTimeString(),
            ])
            ->tap(function ($activity) use ($source): void {
                $activity->company_id = $source->company_id;
            })
            ->log('Crew redeployed');
    }

    private function reloadLocked(int $companyId, int $assignmentId): CrewAssignment
    {
        $assignment = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($assignmentId)
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw CrewMovementException::make(
                'Crew assignment not found for this company.',
                'assignment_not_found',
            );
        }

        $assignment->load([
            'employee',
            'phases',
            'currentPhase',
            'previousAssignment',
            'planningAssignment',
        ]);

        return $assignment;
    }

    private function assertNoActiveAssignment(int $companyId, int $employeeId, ?int $exceptId = null): void
    {
        $query = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', CrewAssignmentStatus::Active)
            ->lockForUpdate();

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $conflict = $query->first();

        if ($conflict !== null) {
            throw CrewMovementException::make(
                sprintf(
                    'Employee already has an active assignment (%s).',
                    $conflict->assignment_no,
                ),
                'active_assignment_exists',
            );
        }
    }

    private function assertStatus(CrewAssignment $assignment, CrewAssignmentStatus $expected): void
    {
        if ($assignment->status !== $expected) {
            throw CrewMovementException::make(
                sprintf('Assignment must be %s.', $expected->value),
                'invalid_assignment_status',
            );
        }
    }

    private function requireCurrentPhase(
        CrewAssignment $assignment,
        ?CrewPhaseCode $expected = null,
    ): CrewAssignmentPhase {
        $current = $assignment->currentPhase;

        if ($current === null) {
            throw CrewMovementException::make(
                'Assignment has no current phase.',
                'missing_current_phase',
            );
        }

        if ($expected !== null && $current->phase_code !== $expected) {
            throw CrewMovementException::make(
                sprintf('Current phase must be %s.', $expected->label()),
                'unexpected_current_phase',
            );
        }

        return $current;
    }

    private function assertPhaseStatus(CrewAssignmentPhase $phase, CrewPhaseStatus $expected): void
    {
        if ($phase->status !== $expected) {
            throw CrewMovementException::make(
                sprintf('Current phase must be %s.', $expected->value),
                'invalid_phase_status',
            );
        }
    }

    /**
     * @param  list<CrewPhaseCode>  $allowed
     */
    private function requireNextPhaseCode(array $payload, array $allowed): CrewPhaseCode
    {
        $raw = (string) ($payload['starting_phase'] ?? $payload['next_phase'] ?? '');
        $code = CrewPhaseCode::tryFrom($raw);

        if ($code === null || ! in_array($code, $allowed, true)) {
            throw CrewMovementException::make(
                'Invalid next_phase for this action.',
                'invalid_next_phase',
            );
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireOccurredAt(int $companyId, array $payload): CarbonInterface
    {
        if (empty($payload['occurred_at'])) {
            throw CrewMovementException::make(
                'occurred_at is required.',
                'occurred_at_required',
            );
        }

        return $this->parseTimestamp($companyId, (string) $payload['occurred_at']);
    }

    private function parseTimestamp(int $companyId, string $value): CarbonInterface
    {
        $timezone = $this->companyTimezone($companyId);

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable $e) {
            throw CrewMovementException::make(
                'Invalid timestamp value.',
                'invalid_timestamp',
                previous: $e,
            );
        }
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertCompanyOwnedMaster(
        int $companyId,
        string $modelClass,
        int $id,
        string $label,
    ): void {
        $this->masters->assertUsable($companyId, $modelClass, $id, $label);
    }
}
