<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeSeaService;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use App\Support\CrewMovements\SeaServiceSyncService;
use Illuminate\Support\Facades\DB;

final class HistoricalCrewAssignmentService
{
    public function __construct(
        private readonly HistoricalCrewAssignmentValidator $validator,
        private readonly CrewAssignmentNumberGenerator $numberGenerator,
        private readonly CrewAssignmentInvariantGuard $guard,
        private readonly SeaServiceSyncService $seaServiceSync,
    ) {}

    public function preview(HistoricalCrewAssignmentData $data, ?User $actor = null): HistoricalCrewAssignmentPreview
    {
        $result = $this->validator->validate($data, $actor);
        $result->assertValid();

        return $result->toPreview();
    }

    public function create(HistoricalCrewAssignmentData $data, ?int $actorId = null): CrewAssignment
    {
        return DB::transaction(function () use ($data, $actorId): CrewAssignment {
            // Lock employee assignments to prevent concurrency race conditions & double submits
            CrewAssignment::query()
                ->where('company_id', $data->companyId)
                ->where('employee_id', $data->employeeId)
                ->lockForUpdate()
                ->get(['id']);

            $actor = $actorId !== null && $actorId > 0 ? User::query()->find($actorId) : null;
            $validationResult = $this->validator->validate($data, $actor);
            $validationResult->assertValid();

            $assignmentNo = $this->numberGenerator->next($data->companyId);

            $assignment = CrewAssignment::query()->create([
                'company_id' => $data->companyId,
                'assignment_no' => $assignmentNo,
                'employee_id' => $data->employeeId,
                'rank_id' => $data->rankId,
                'client_id' => $data->clientId,
                'vessel_id' => $data->vesselId,
                'status' => CrewAssignmentStatus::Completed,
                'started_at' => $data->earliestActualStart(),
                'closed_at' => $data->assignmentClosedAt ?? $data->latestActualEnd(),
                'source' => $data->source,
                'remarks' => $data->remarks,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $phases = $data->phasesToCreate();
            /** @var list<CrewAssignmentPhase> $createdPhases */
            $createdPhases = [];
            $seq = 1;

            foreach ($phases as $phaseData) {
                $phase = CrewAssignmentPhase::query()->create([
                    'company_id' => $data->companyId,
                    'crew_assignment_id' => $assignment->id,
                    'phase_code' => $phaseData['phase_code'],
                    'sequence' => $seq++,
                    'status' => CrewPhaseStatus::Completed,
                    'actual_start_at' => $phaseData['actual_start_at'],
                    'actual_end_at' => $phaseData['actual_end_at'],
                    'remarks' => $phaseData['remarks'],
                    'started_by' => $actorId,
                    'completed_by' => $actorId,
                ]);

                $createdPhases[] = $phase;
            }

            $lastPhase = end($createdPhases);
            if ($lastPhase !== false) {
                $assignment->update(['current_phase_id' => $lastPhase->id]);
            }

            $this->guard->assertValid($assignment);

            // Sea service sync or deduplication
            $p4Phase = collect($createdPhases)->first(
                fn (CrewAssignmentPhase $p): bool => $p->phase_code === CrewPhaseCode::OnVessel
            );

            if ($p4Phase !== null && $this->seaServiceSync->isEnabled($data->companyId)) {
                $startDate = $data->joinedVesselAt->toDateString();
                $endDate = $data->disembarkedAt->toDateString();

                $matchingUnlinked = EmployeeSeaService::query()
                    ->where('company_id', $data->companyId)
                    ->where('employee_id', $data->employeeId)
                    ->where('vessel_id', $data->vesselId)
                    ->whereDate('start_date', $startDate)
                    ->whereDate('end_date', $endDate)
                    ->whereNull('crew_assignment_phase_id')
                    ->first();

                if ($matchingUnlinked !== null) {
                    $duration = $data->seaServiceDuration();
                    $matchingUnlinked->update([
                        'crew_assignment_phase_id' => $p4Phase->id,
                        'rank_id' => $data->rankId,
                        'client_id' => $data->clientId,
                        'total_months' => $duration['months'],
                        'total_days' => $duration['days'],
                    ]);
                } else {
                    $this->seaServiceSync->syncFromPhase($p4Phase);
                }
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
                    'source' => $data->source,
                ])
                ->log('created historical crew assignment');

            return $assignment->fresh(['phases', 'currentPhase', 'employee', 'vessel', 'rank', 'client']);
        });
    }
}
