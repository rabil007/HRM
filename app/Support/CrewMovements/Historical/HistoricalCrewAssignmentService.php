<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
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

    public function create(
        HistoricalCrewAssignmentData $data,
        ?int $actorId = null,
        ?int $importBatchId = null,
    ): CrewAssignment {
        return DB::transaction(function () use ($data, $actorId, $importBatchId): CrewAssignment {
            // 1. Lock the employee row to serialize concurrent writes for this employee
            Employee::query()
                ->where('company_id', $data->companyId)
                ->whereKey($data->employeeId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Lock existing assignment rows for this employee
            CrewAssignment::query()
                ->where('company_id', $data->companyId)
                ->where('employee_id', $data->employeeId)
                ->lockForUpdate()
                ->get(['id']);

            // 3. Re-run authoritative validation under the lock
            $actor = $actorId !== null && $actorId > 0 ? User::query()->find($actorId) : null;
            $validationResult = $this->validator->validate($data, $actor);
            $validationResult->assertValid();

            // 4. Generate assignment number with locking
            $assignmentNo = $this->numberGenerator->next($data->companyId);

            $chronologicalPreviousId = $this->resolveChronologicalPreviousId($data);

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
                'previous_assignment_id' => $chronologicalPreviousId,
                'source' => $data->source,
                'historical_import_batch_id' => $importBatchId,
                'remarks' => $data->remarks,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->relinkChronologicalSuccessor($assignment, $chronologicalPreviousId);

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

            // 5. Sea service sync or safe deduplication
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
                    // Safe linking: do not silently rewrite existing HR history (days, rank, client)
                    $matchingUnlinked->crew_assignment_phase_id = $p4Phase->id;
                    if ($matchingUnlinked->rank_id === null) {
                        $matchingUnlinked->rank_id = $data->rankId;
                    }
                    if ($matchingUnlinked->client_id === null && $data->clientId !== null) {
                        $matchingUnlinked->client_id = $data->clientId;
                    }
                    $matchingUnlinked->save();
                } else {
                    $this->seaServiceSync->syncFromPhase($p4Phase);
                }
            }

            // 6. Activity audit logging with company_id on activity row
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
                    'historical_joined_vessel_at' => $data->joinedVesselAt->toDateString(),
                    'historical_disembarked_at' => $data->disembarkedAt->toDateString(),
                    'source' => $data->source,
                ])
                ->tap(function ($activity) use ($assignment): void {
                    $activity->company_id = $assignment->company_id;
                })
                ->log('Historical crew assignment created');

            return $assignment->fresh(['phases', 'currentPhase', 'employee', 'vessel', 'rank', 'client']);
        });
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
