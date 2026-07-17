<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\User;

final class CrewMovementCorrectionPresenter
{
    public function __construct(
        private readonly CrewMovementCorrectionValueSnapshot $snapshot = new CrewMovementCorrectionValueSnapshot,
        private readonly CrewMovementCorrectionFieldCatalog $catalog = new CrewMovementCorrectionFieldCatalog,
        private readonly CrewMovementCorrectionSla $sla = new CrewMovementCorrectionSla,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listItem(CrewMovementCorrection $correction, ?string $timezone = null): array
    {
        $assignment = $correction->assignment;
        $phase = $correction->phase;
        $timezone ??= (string) ($correction->company?->timezone
            ?? config('app.timezone', 'UTC'));

        return [
            'id' => $correction->id,
            'status' => $correction->status->value,
            'status_label' => $correction->status->label(),
            'reason' => $correction->reason,
            'decision_notes' => $correction->decision_notes,
            'requested_at' => ($correction->requested_at ?? $correction->created_at)?->toIso8601String(),
            'decided_at' => $correction->decided_at?->toIso8601String(),
            ...$this->sla->forCorrection($correction, $timezone),
            'assignment' => $assignment ? [
                'id' => $assignment->id,
                'assignment_no' => $assignment->assignment_no,
                'employee' => $assignment->employee ? [
                    'id' => $assignment->employee->id,
                    'name' => $assignment->employee->name,
                    'employee_no' => $assignment->employee->employee_no,
                ] : null,
                'vessel' => $assignment->vessel ? [
                    'id' => $assignment->vessel->id,
                    'name' => $assignment->vessel->name,
                ] : null,
            ] : null,
            'phase' => $phase ? [
                'id' => $phase->id,
                'phase_code' => $phase->phase_code->value,
                'phase_label' => $phase->phase_code->label(),
                'status' => $phase->status->value,
                'status_label' => $phase->status->label(),
            ] : null,
            'requester' => $this->userSummary($correction->requester),
            'decision_maker' => $this->userSummary($correction->decisionMaker),
            'field_count' => count($correction->proposed_values ?? []),
            'has_conflict' => $this->hasConflict($correction),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(CrewMovementCorrection $correction, ?User $viewer = null): array
    {
        $assignment = $correction->assignment;
        $phase = $correction->phase;
        $live = [];

        if ($assignment !== null && $phase !== null) {
            foreach (array_keys($correction->proposed_values ?? []) as $field) {
                $value = $this->snapshot->rawValue($assignment, $phase, (string) $field);
                $live[(string) $field] = [
                    'value' => $this->snapshot->serializeValue((string) $field, $value),
                    'display' => $this->snapshot->displayValue($assignment, (string) $field, $value),
                ];
            }
        }

        $hasConflict = $this->hasConflict($correction);

        return [
            ...$this->listItem($correction),
            'original_values' => $correction->original_values ?? [],
            'proposed_values' => $correction->proposed_values ?? [],
            'applied_values' => $correction->applied_values,
            'live_values' => $live,
            'has_conflict' => $hasConflict,
            'can_approve' => $correction->status === CrewMovementCorrectionStatus::Pending
                && CrewMovementCorrectionAccess::canApproveCorrection($viewer, $correction),
            'can_reject' => $correction->status === CrewMovementCorrectionStatus::Pending
                && ($viewer?->can('crew_operations.corrections.approve') ?? false),
            'can_cancel' => $correction->status === CrewMovementCorrectionStatus::Pending
                && $viewer !== null
                && (
                    (int) $correction->requested_by === (int) $viewer->id
                    || $viewer->can('crew_operations.corrections.approve')
                    || $viewer->can('crew_operations.corrections.override')
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignmentSummary(CrewAssignment $assignment): array
    {
        $corrections = $assignment->relationLoaded('corrections')
            ? $assignment->corrections
            : $assignment->corrections()->with(['requester:id,name', 'decisionMaker:id,name', 'phase'])->latest('id')->get();

        $pending = $corrections->where('status', CrewMovementCorrectionStatus::Pending)->values();
        $history = $corrections->values();
        $timezone = (string) ($assignment->company?->timezone
            ?? config('app.timezone', 'UTC'));

        return [
            'pending' => $pending
                ->map(fn (CrewMovementCorrection $correction) => $this->listItem($correction, $timezone))
                ->all(),
            'history' => $history
                ->map(fn (CrewMovementCorrection $correction) => $this->listItem($correction, $timezone))
                ->all(),
            'pending_count' => $pending->count(),
            'approved_count' => $corrections->where('status', CrewMovementCorrectionStatus::Approved)->count(),
            'correctable_phases' => $assignment->phases
                ->filter(fn (CrewAssignmentPhase $phase) => $phase->actual_start_at !== null
                    && in_array($phase->status->value, ['active', 'completed'], true))
                ->map(fn (CrewAssignmentPhase $phase) => [
                    'id' => $phase->id,
                    'phase_code' => $phase->phase_code->value,
                    'phase_label' => $phase->phase_code->label(),
                    'status' => $phase->status->value,
                    'status_label' => $phase->status->label(),
                    'actual_start_at' => $phase->actual_start_at?->toIso8601String(),
                    'actual_end_at' => $phase->actual_end_at?->toIso8601String(),
                    'remarks' => $phase->remarks,
                    'details' => $phase->details,
                    'allowed_fields' => $this->catalog->allowedFields($phase),
                    'has_pending_correction' => $phase->relationLoaded('pendingCorrections')
                        ? $phase->pendingCorrections->isNotEmpty()
                        : $phase->pendingCorrections()->exists(),
                    'current_values' => $this->snapshot->capture(
                        $assignment,
                        $phase,
                        $this->catalog->allowedFields($phase),
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function userSummary(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    private function hasConflict(CrewMovementCorrection $correction): bool
    {
        if ($correction->status !== CrewMovementCorrectionStatus::Pending) {
            return false;
        }

        $assignment = $correction->assignment;
        $phase = $correction->phase;

        if ($assignment === null || $phase === null) {
            return true;
        }

        return ! $this->snapshot->valuesMatch($correction->original_values ?? [], $assignment, $phase);
    }
}
