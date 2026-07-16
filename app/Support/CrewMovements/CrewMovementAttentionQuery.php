<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;

class CrewMovementAttentionQuery
{
    private const PHASE_STALE_DAYS = 14;

    private const DRAFT_STALE_DAYS = 7;

    /**
     * @return list<array{
     *     code: string,
     *     severity: string,
     *     label: string,
     *     message: string,
     *     date: string|null
     * }>
     */
    public static function forAssignment(CrewAssignment $assignment): array
    {
        $warnings = [];
        $current = $assignment->currentPhase;
        $now = now($assignment->company->timezone ?? 'UTC');

        if ($assignment->status === CrewAssignmentStatus::Draft && $assignment->created_at) {
            $age = $assignment->created_at->diffInDays($now);
            if ($age >= self::DRAFT_STALE_DAYS) {
                $warnings[] = [
                    'code' => 'draft_stale',
                    'severity' => 'warning',
                    'label' => 'Draft Stale',
                    'message' => sprintf('Draft assignment is %d days old', $age),
                    'date' => $assignment->created_at->toDateString(),
                ];
            }
        }

        if ($assignment->status === CrewAssignmentStatus::Active && $current === null) {
            $warnings[] = [
                'code' => 'missing_current_phase',
                'severity' => 'critical',
                'label' => 'Missing Phase',
                'message' => 'Active assignment has no current phase',
                'date' => null,
            ];
        }

        if ($current !== null && $current->actual_start_at !== null) {
            $daysInPhase = $current->actual_start_at->diffInDays($now);
            if ($daysInPhase > self::PHASE_STALE_DAYS) {
                $warnings[] = [
                    'code' => 'phase_stale',
                    'severity' => 'warning',
                    'label' => 'Phase Active Long',
                    'message' => sprintf('%s active for %d days', $current->phase_code->label(), $daysInPhase),
                    'date' => $current->actual_start_at->toDateString(),
                ];
            }
        }

        if ($assignment->planned_join_at
            && $assignment->status === CrewAssignmentStatus::Active
            && $current?->phase_code !== CrewPhaseCode::OnVessel
            && $assignment->planned_join_at->isBefore($now)) {
            $warnings[] = [
                'code' => 'planned_join_overdue',
                'severity' => 'critical',
                'label' => 'Join Overdue',
                'message' => 'Planned join date has passed',
                'date' => $assignment->planned_join_at->toDateString(),
            ];
        }

        if ($assignment->planned_signoff_at
            && $current?->phase_code === CrewPhaseCode::OnVessel
            && $assignment->planned_signoff_at->isBefore($now)) {
            $warnings[] = [
                'code' => 'planned_signoff_overdue',
                'severity' => 'critical',
                'label' => 'Sign-off Overdue',
                'message' => 'Planned sign-off date has passed',
                'date' => $assignment->planned_signoff_at->toDateString(),
            ];
        }

        if ($assignment->status === CrewAssignmentStatus::Active
            && ($current?->phase_code === CrewPhaseCode::ReadyToJoin
                || $current?->phase_code === CrewPhaseCode::OnVessel)) {
            if ($assignment->vessel_id === null) {
                $warnings[] = [
                    'code' => 'missing_vessel',
                    'severity' => 'critical',
                    'label' => 'Missing Vessel',
                    'message' => 'Vessel not assigned before/during join',
                    'date' => null,
                ];
            }
            if ($assignment->rank_id === null) {
                $warnings[] = [
                    'code' => 'missing_rank',
                    'severity' => 'critical',
                    'label' => 'Missing Rank',
                    'message' => 'Rank not assigned before/during join',
                    'date' => null,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return array{
     *     total: int,
     *     needs_attention: int,
     *     by_phase: array<string, int>
     * }
     */
    public static function summaryCounts(int $companyId): array
    {
        $assignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active])
            ->with(['currentPhase', 'company'])
            ->get();

        $byPhase = [];
        $needsAttention = 0;

        foreach ($assignments as $assignment) {
            $phaseCode = $assignment->currentPhase?->phase_code?->value ?? 'unknown';
            $byPhase[$phaseCode] = ($byPhase[$phaseCode] ?? 0) + 1;

            if (self::forAssignment($assignment) !== []) {
                $needsAttention++;
            }
        }

        return [
            'total' => $assignments->count(),
            'needs_attention' => $needsAttention,
            'by_phase' => $byPhase,
        ];
    }
}
