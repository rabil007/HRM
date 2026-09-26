<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\User;
use App\Support\CrewAccommodation\CrewAccommodationStayIntegrity;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use App\Support\CrewMovements\SeaServiceSyncService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HistoricalCrewAssignmentService
{
    public function __construct(
        private readonly HistoricalCrewAssignmentValidator $validator,
        private readonly CrewAssignmentNumberGenerator $numberGenerator,
        private readonly CrewAssignmentInvariantGuard $guard,
        private readonly SeaServiceSyncService $seaServiceSync,
        private readonly HistoricalSeaServiceMatchResolver $seaServiceMatchResolver,
    ) {}

    public function preview(HistoricalCrewAssignmentData $data, ?User $actor = null): HistoricalCrewAssignmentPreview
    {
        $result = $this->validator->validate($data, $actor);
        $result->assertValid();

        return $result->toPreview();
    }

    public function create(
        HistoricalCrewAssignmentData $data,
        ?int $actorId = null,
        ?int $importBatchId = null,
    ): CrewAssignment {
        return DB::transaction(function () use ($data, $actorId, $importBatchId): CrewAssignment {
            Employee::query()
                ->where('company_id', $data->companyId)
                ->whereKey($data->employeeId)
                ->lockForUpdate()
                ->firstOrFail();

            CrewAssignment::query()
                ->where('company_id', $data->companyId)
                ->where('employee_id', $data->employeeId)
                ->lockForUpdate()
                ->get(['id']);

            $actor = $actorId !== null && $actorId > 0 ? User::query()->find($actorId) : null;
            $validationResult = $this->validator->validate($data, $actor);
            $validationResult->assertValid();

            $reconstruction = $data->reconstruction();
            $assignmentNo = $this->numberGenerator->next($data->companyId);
            $chronologicalPreviousId = $this->resolveChronologicalPreviousId($data);

            $assignment = CrewAssignment::query()->create([
                'company_id' => $data->companyId,
                'assignment_no' => $assignmentNo,
                'employee_id' => $data->employeeId,
                'rank_id' => $data->rankId,
                'client_id' => $data->clientId,
                'vessel_id' => $data->vesselId,
                'status' => $reconstruction['assignment_status'],
                'started_at' => $data->earliestActualStart(),
                'closed_at' => $reconstruction['closed_at'],
                'previous_assignment_id' => $chronologicalPreviousId,
                'source' => $data->source,
                'historical_import_batch_id' => $importBatchId,
                'remarks' => $data->remarks,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->relinkChronologicalSuccessor($assignment, $chronologicalPreviousId);

            /** @var list<CrewAssignmentPhase> $createdPhases */
            $createdPhases = [];
            $seq = 1;
            $currentPhaseId = null;

            foreach ($reconstruction['phases'] as $phaseData) {
                $isCompleted = $phaseData['status'] === CrewPhaseStatus::Completed;

                $phase = CrewAssignmentPhase::query()->create([
                    'company_id' => $data->companyId,
                    'crew_assignment_id' => $assignment->id,
                    'phase_code' => $phaseData['phase_code'],
                    'sequence' => $seq++,
                    'status' => $phaseData['status'],
                    'actual_start_at' => $phaseData['actual_start_at'],
                    'actual_end_at' => $phaseData['actual_end_at'],
                    'remarks' => $phaseData['remarks'],
                    'started_by' => $actorId,
                    'completed_by' => $isCompleted ? $actorId : null,
                ]);

                $createdPhases[] = $phase;

                if ($phaseData['status'] === CrewPhaseStatus::Active
                    || $phaseData['phase_code'] === CrewPhaseCode::HomeRedeploy) {
                    $currentPhaseId = $phase->id;
                }
            }

            if ($currentPhaseId === null && $createdPhases !== []) {
                $currentPhaseId = $createdPhases[array_key_last($createdPhases)]->id;
            }

            if ($currentPhaseId !== null) {
                $assignment->update(['current_phase_id' => $currentPhaseId]);
            }

            $accommodationCreated = $this->createAccommodationStays($assignment, $data, $createdPhases, $actorId);

            $this->guard->assertValid($assignment->fresh(['phases', 'currentPhase']));

            $joinedVesselAt = $data->joinedVesselAt();
            $disembarkedAt = $data->disembarkedAt();

            if ($this->seaServiceSync->isEnabled($data->companyId) && $joinedVesselAt !== null) {
                $this->syncSeaServiceForHistoricalP4(
                    data: $data,
                    createdPhases: $createdPhases,
                    joinedVesselAt: $joinedVesselAt,
                    disembarkedAt: $disembarkedAt,
                );
            }

            activity()
                ->performedOn($assignment)
                ->causedBy($actorId)
                ->event('historical_crew_assignment_created')
                ->withProperties([
                    'company_id' => $data->companyId,
                    'assignment_id' => $assignment->id,
                    'assignment_no' => $assignment->assignment_no,
                    'employee_id' => $data->employeeId,
                    'vessel_id' => $data->vesselId,
                    'rank_id' => $data->rankId,
                    'historical_start' => $assignment->started_at?->toIso8601String(),
                    'historical_end' => $assignment->closed_at?->toIso8601String(),
                    'sign_on_standby_from' => $data->signOnStandbyFrom?->toDateString(),
                    'sign_on_standby_to' => $data->signOnStandbyTo?->toDateString(),
                    'onsite_from' => $data->onsiteFrom?->toDateString(),
                    'onsite_to' => $data->onsiteTo?->toDateString(),
                    'sign_off_standby_from' => $data->signOffStandbyFrom?->toDateString(),
                    'sign_off_standby_to' => $data->signOffStandbyTo?->toDateString(),
                    'home_available_from' => $data->homeAvailableFrom?->toDateString(),
                    'inferred_phase' => $reconstruction['inferred_state']['phase_code']->value,
                    'assignment_status' => $reconstruction['assignment_status']->value,
                    'accommodation_created' => $accommodationCreated,
                    'source' => $data->source,
                ])
                ->tap(function ($activity) use ($assignment): void {
                    $activity->company_id = $assignment->company_id;
                })
                ->log('Past crew data saved');

            return $assignment->fresh(['phases', 'currentPhase', 'employee', 'vessel', 'rank', 'client', 'accommodationStays']);
        });
    }

    /**
     * @param  list<CrewAssignmentPhase>  $createdPhases
     * @return list<array{stay_type: string, accommodation_status: string}>
     */
    private function createAccommodationStays(
        CrewAssignment $assignment,
        HistoricalCrewAssignmentData $data,
        array $createdPhases,
        ?int $actorId,
    ): array {
        $created = [];

        $p2a = collect($createdPhases)->first(
            fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::JoinStandby
        );
        $p5 = collect($createdPhases)->first(
            fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::DemobStandby
        );

        if ($p2a !== null) {
            $stay = $this->createStandbyStay(
                assignment: $assignment,
                phase: $p2a,
                stayType: CrewAccommodationStayType::PreJoin,
                choice: $data->signOnAccommodation,
                hotelId: $data->signOnHotelId,
                roomTypeId: $data->signOnRoomTypeId,
                checkIn: $data->signOnHotelCheckIn ?? $data->signOnStandbyFrom,
                checkOut: $data->signOnHotelCheckOut ?? $data->signOnStandbyTo,
                actorId: $actorId,
            );

            if ($stay !== null) {
                $created[] = [
                    'stay_type' => $stay->stay_type->value,
                    'accommodation_status' => $stay->accommodation_status->value,
                ];
            }
        }

        if ($p5 !== null) {
            $stay = $this->createStandbyStay(
                assignment: $assignment,
                phase: $p5,
                stayType: CrewAccommodationStayType::PostSignoff,
                choice: $data->signOffAccommodation,
                hotelId: $data->signOffHotelId,
                roomTypeId: $data->signOffRoomTypeId,
                checkIn: $data->signOffHotelCheckIn ?? $data->signOffStandbyFrom,
                checkOut: $data->signOffHotelCheckOut ?? $data->signOffStandbyTo,
                actorId: $actorId,
            );

            if ($stay !== null) {
                $created[] = [
                    'stay_type' => $stay->stay_type->value,
                    'accommodation_status' => $stay->accommodation_status->value,
                ];
            }
        }

        return $created;
    }

    private function createStandbyStay(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        CrewAccommodationStayType $stayType,
        string $choice,
        ?int $hotelId,
        ?int $roomTypeId,
        mixed $checkIn,
        mixed $checkOut,
        ?int $actorId,
    ): ?CrewAccommodationStay {
        if ($choice === HistoricalCrewAssignmentData::ACCOMMODATION_NOT_RECORDED) {
            return null;
        }

        $companyId = (int) $assignment->company_id;

        if ($choice === HistoricalCrewAssignmentData::ACCOMMODATION_NO_ACCOMMODATION) {
            $stay = CrewAccommodationStay::query()->create([
                'company_id' => $companyId,
                'crew_assignment_id' => $assignment->id,
                'stay_type' => $stayType,
                'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
                'started_from_phase_id' => $phase->id,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            CrewAccommodationStayIntegrity::assertValid($stay);

            return $stay;
        }

        $stay = CrewAccommodationStay::query()->create([
            'company_id' => $companyId,
            'crew_assignment_id' => $assignment->id,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'stay_type' => $stayType,
            'accommodation_status' => CrewAccommodationStatus::Hotel,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'started_from_phase_id' => $phase->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        CrewAccommodationStayIntegrity::assertValid($stay);

        return $stay;
    }

    /**
     * @param  list<CrewAssignmentPhase>  $createdPhases
     */
    private function syncSeaServiceForHistoricalP4(
        HistoricalCrewAssignmentData $data,
        array $createdPhases,
        CarbonInterface $joinedVesselAt,
        ?CarbonInterface $disembarkedAt,
    ): void {
        $isOpen = $disembarkedAt === null;

        $p4Phase = collect($createdPhases)->first(
            function (CrewAssignmentPhase $phase) use ($isOpen): bool {
                if ($phase->phase_code !== CrewPhaseCode::OnVessel || $phase->actual_start_at === null) {
                    return false;
                }

                if ($isOpen) {
                    return $phase->status === CrewPhaseStatus::Active && $phase->actual_end_at === null;
                }

                return $phase->status === CrewPhaseStatus::Completed && $phase->actual_end_at !== null;
            }
        );

        if ($p4Phase === null) {
            return;
        }

        $startDate = $joinedVesselAt->toDateString();
        $endDate = $disembarkedAt?->toDateString();
        $seaDays = $isOpen ? 0 : $data->seaServiceDuration()['days'];

        EmployeeSeaService::query()
            ->where('company_id', $data->companyId)
            ->where('employee_id', $data->employeeId)
            ->lockForUpdate()
            ->get(['id']);

        $existingSeaServices = EmployeeSeaService::query()
            ->where('company_id', $data->companyId)
            ->where('employee_id', $data->employeeId)
            ->with(['vessel'])
            ->lockForUpdate()
            ->get();

        $exactMatch = $this->seaServiceMatchResolver->resolveExactMatch(
            data: $data,
            seaStartDate: $startDate,
            seaEndDate: $endDate,
            seaDays: $seaDays,
            existingForEmployee: $existingSeaServices,
            lockForUpdate: true,
        );

        if ($exactMatch['status'] === 'conflict') {
            throw ValidationException::withMessages([
                'sea_service' => [$exactMatch['error'] ?? $exactMatch['message']],
            ]);
        }

        $overlapMessage = $this->seaServiceMatchResolver->firstOverlappingConflictMessage(
            existingForEmployee: $existingSeaServices,
            data: $data,
            seaStartDate: $startDate,
            seaEndDate: $endDate,
            exactMatchIdToIgnore: $exactMatch['existing_id'],
        );

        if ($overlapMessage !== null) {
            throw ValidationException::withMessages([
                'sea_service' => [$overlapMessage],
            ]);
        }

        if ($exactMatch['status'] === 'will_link' && $exactMatch['existing_id'] !== null) {
            $matchingUnlinked = EmployeeSeaService::query()
                ->whereKey($exactMatch['existing_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $matchingUnlinked->crew_assignment_phase_id = $p4Phase->id;
            if ($matchingUnlinked->rank_id === null) {
                $matchingUnlinked->rank_id = $data->rankId;
            }
            if ($matchingUnlinked->client_id === null && $data->clientId !== null) {
                $matchingUnlinked->client_id = $data->clientId;
            }
            $matchingUnlinked->save();

            return;
        }

        $this->seaServiceSync->syncFromPhase($p4Phase);
    }

    private function resolveChronologicalPreviousId(HistoricalCrewAssignmentData $data): ?int
    {
        $previous = CrewAssignment::query()
            ->where('company_id', $data->companyId)
            ->where('employee_id', $data->employeeId)
            ->whereNull('voided_at')
            ->where('started_at', '<', $data->earliestActualStart())
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first(['id']);

        return $previous?->id;
    }

    private function relinkChronologicalSuccessor(CrewAssignment $assignment, ?int $previousId): void
    {
        $next = CrewAssignment::query()
            ->where('company_id', $assignment->company_id)
            ->where('employee_id', $assignment->employee_id)
            ->whereNull('voided_at')
            ->whereKeyNot($assignment->id)
            ->where('started_at', '>', $assignment->started_at)
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return;
        }

        if ($next->previous_assignment_id === $previousId) {
            $next->update(['previous_assignment_id' => $assignment->id]);
        }
    }
}
