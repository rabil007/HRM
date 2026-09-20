<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\User;

final class VoidCrewAssignmentImpactResolver
{
    public function __construct(
        private readonly CrewAssignmentVoidGuard $guard,
    ) {}

    /**
     * @param  list<int>  $assignmentIds
     * @return array{
     *     total_assignments: int,
     *     total_sea_service_records: int,
     *     total_training_records: int,
     *     can_delete_sea_service: bool,
     *     can_delete_training: bool,
     *     has_sea_service: bool,
     *     has_training: bool,
     *     has_protected_blockers: bool,
     *     blocked_assignment_nos: list<string>,
     *     assignments: list<array{
     *         id: int,
     *         assignment_no: string,
     *         employee_name: string,
     *         current_phase: array{code: string, label: string, status: string|null}|null,
     *         sea_service_count: int,
     *         training_count: int,
     *         blockers: list<array{code: string, message: string}>,
     *         has_protected_blockers: bool,
     *         has_sea_service: bool
     *     }>
     * }
     */
    public function resolve(int $companyId, array $assignmentIds, ?User $actor): array
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $assignmentIds)));

        if ($uniqueIds === []) {
            return [
                'total_assignments' => 0,
                'total_sea_service_records' => 0,
                'total_training_records' => 0,
                'can_delete_sea_service' => $actor?->can('sea_services.delete') ?? false,
                'can_delete_training' => $actor?->can('training.delete') ?? false,
                'has_sea_service' => false,
                'has_training' => false,
                'has_protected_blockers' => false,
                'blocked_assignment_nos' => [],
                'assignments' => [],
            ];
        }

        $assignments = CrewAssignmentAccess::queryForCompany($companyId, $actor)
            ->withTrashed()
            ->whereIn('crew_assignments.id', $uniqueIds)
            ->with(['currentPhase', 'employee'])
            ->get();

        if ($assignments->count() !== count($uniqueIds)) {
            abort(404, 'One or more selected assignments could not be found in the active company.');
        }

        $phases = CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $uniqueIds)
            ->get(['id', 'crew_assignment_id', 'company_id']);

        $phaseToAssignment = [];
        $phaseIds = [];
        foreach ($phases as $phase) {
            $pId = (int) $phase->id;
            $phaseIds[] = $pId;
            $phaseToAssignment[$pId] = (int) $phase->crew_assignment_id;
        }

        $seaServiceCounts = [];
        if ($phaseIds !== []) {
            $seaServices = EmployeeSeaService::query()
                ->where('company_id', $companyId)
                ->whereIn('crew_assignment_phase_id', $phaseIds)
                ->get(['id', 'crew_assignment_phase_id']);

            foreach ($seaServices as $seaService) {
                $assignmentId = $phaseToAssignment[(int) $seaService->crew_assignment_phase_id] ?? null;
                if ($assignmentId !== null) {
                    $seaServiceCounts[$assignmentId] = ($seaServiceCounts[$assignmentId] ?? 0) + 1;
                }
            }
        }

        $trainingCounts = [];
        if ($phaseIds !== []) {
            $trainings = EmployeeTraining::query()
                ->where('company_id', $companyId)
                ->whereIn('source_crew_assignment_phase_id', $phaseIds)
                ->get(['id', 'source_crew_assignment_phase_id']);

            foreach ($trainings as $training) {
                $assignmentId = $phaseToAssignment[(int) $training->source_crew_assignment_phase_id] ?? null;
                if ($assignmentId !== null) {
                    $trainingCounts[$assignmentId] = ($trainingCounts[$assignmentId] ?? 0) + 1;
                }
            }
        }

        $canDeleteSeaService = $actor?->can('sea_services.delete') ?? false;
        $canDeleteTraining = $actor?->can('training.delete') ?? false;

        $allBlockers = $this->guard->batchBlockers($assignments, $companyId);

        $items = [];
        $totalSeaService = 0;
        $totalTraining = 0;
        $anyProtectedBlockers = false;
        $blockedNos = [];

        foreach ($assignments as $assignment) {
            $seaCount = $seaServiceCounts[$assignment->id] ?? 0;
            $trainCount = $trainingCounts[$assignment->id] ?? 0;
            $totalSeaService += $seaCount;
            $totalTraining += $trainCount;

            $blockers = $allBlockers[(int) $assignment->id] ?? [];
            $protectedBlockers = array_values(array_filter(
                $blockers,
                fn (array $b): bool => $b['code'] !== 'sea_service_exists',
            ));

            $hasProtected = $protectedBlockers !== [];
            if ($hasProtected) {
                $anyProtectedBlockers = true;
                $blockedNos[] = $assignment->assignment_no;
            }

            $currentPhase = $assignment->currentPhase;

            $items[] = [
                'id' => (int) $assignment->id,
                'assignment_no' => (string) $assignment->assignment_no,
                'employee_name' => (string) ($assignment->employee?->name ?? 'Unknown'),
                'current_phase' => $currentPhase !== null ? [
                    'code' => (string) $currentPhase->phase_code?->value,
                    'label' => (string) ($currentPhase->phase_code?->label() ?? $currentPhase->phase_code?->value),
                    'status' => $currentPhase->status?->value,
                ] : null,
                'sea_service_count' => $seaCount,
                'training_count' => $trainCount,
                'blockers' => $blockers,
                'has_protected_blockers' => $hasProtected,
                'has_sea_service' => $seaCount > 0,
            ];
        }

        return [
            'total_assignments' => count($items),
            'total_sea_service_records' => $totalSeaService,
            'total_training_records' => $totalTraining,
            'can_delete_sea_service' => $canDeleteSeaService,
            'can_delete_training' => $canDeleteTraining,
            'has_sea_service' => $totalSeaService > 0,
            'has_training' => $totalTraining > 0,
            'has_protected_blockers' => $anyProtectedBlockers,
            'blocked_assignment_nos' => array_values(array_unique($blockedNos)),
            'assignments' => $items,
        ];
    }
}
