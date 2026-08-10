<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;

class CrewMovementAttentionQuery
{
    private const PHASE_STALE_DAYS = 14;

    private const DRAFT_STALE_DAYS = 7;

    /**
     * @param  array<string, mixed>|null  $tourProgress  Precomputed Tour progress to avoid recalculation.
     * @return list<array{
     *     code: string,
     *     severity: string,
     *     label: string,
     *     message: string,
     *     date: string|null
     * }>
     */
    public static function forAssignment(CrewAssignment $assignment, ?array $tourProgress = null): array
    {
        $warnings = [];
        $current = $assignment->currentPhase;
        $timezone = (string) ($assignment->company?->timezone ?? config('app.timezone', 'UTC'));
        $now = now($timezone);
        $todayLocal = $now->toDateString();

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

        // P4 On Vessel is expected to remain active for the full Tour of Duty.
        // The generic stale-phase threshold is meaningless for P4 — attention
        // for an active On Vessel phase is governed exclusively by Tour of Duty
        // conditions (see the dedicated block below).
        if ($current !== null
            && $current->actual_start_at !== null
            && $current->phase_code !== CrewPhaseCode::OnVessel) {
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
            && $assignment->planned_join_at->copy()->timezone($timezone)->toDateString() < $todayLocal) {
            $warnings[] = [
                'code' => 'planned_join_overdue',
                'severity' => 'critical',
                'label' => 'Join Overdue',
                'message' => 'Planned join date has passed',
                'date' => $assignment->planned_join_at->toDateString(),
            ];
        }

        // Compare company-local calendar dates so midnight planned sign-off
        // due today is not treated as overdue later the same day.
        if ($assignment->planned_signoff_at
            && $current?->phase_code === CrewPhaseCode::OnVessel
            && $current->status === CrewPhaseStatus::Active
            && $assignment->planned_signoff_at->copy()->timezone($timezone)->toDateString() < $todayLocal) {
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

        if ($assignment->status === CrewAssignmentStatus::Active
            && $current?->phase_code === CrewPhaseCode::OnVessel
            && $current->status === CrewPhaseStatus::Active) {
            $progress = $tourProgress ?? (new CrewTourProgress)->forAssignment($assignment, null, $timezone);
            $tourStatus = $progress['tour_status'] ?? null;
            $hasTourWarning = false;

            if ($tourStatus === 'overdue') {
                $hasTourWarning = true;
                $warnings[] = [
                    'code' => 'tour_overdue',
                    'severity' => 'critical',
                    'label' => 'Tour Overdue',
                    'message' => sprintf(
                        'Tour of Duty overdue by %d day(s)',
                        abs((int) ($progress['remaining_tour_days'] ?? 0)),
                    ),
                    'date' => $assignment->planned_signoff_at?->toDateString(),
                ];
            } elseif ($tourStatus === 'due_today') {
                $hasTourWarning = true;
                $warnings[] = [
                    'code' => 'tour_due_today',
                    'severity' => 'critical',
                    'label' => 'Tour Due Today',
                    'message' => 'Planned Sign-Off is due today',
                    'date' => $assignment->planned_signoff_at?->toDateString(),
                ];
            } elseif ($tourStatus === 'due_within_7_days') {
                $hasTourWarning = true;
                $warnings[] = [
                    'code' => 'tour_due_within_7_days',
                    'severity' => 'critical',
                    'label' => 'Tour Due Soon',
                    'message' => sprintf(
                        'Planned Sign-Off in %d day(s)',
                        (int) ($progress['remaining_tour_days'] ?? 0),
                    ),
                    'date' => $assignment->planned_signoff_at?->toDateString(),
                ];
            } elseif ($tourStatus === 'due_within_14_days') {
                $hasTourWarning = true;
                $warnings[] = [
                    'code' => 'tour_due_within_14_days',
                    'severity' => 'warning',
                    'label' => 'Tour Due Within 14 Days',
                    'message' => sprintf(
                        'Planned Sign-Off in %d day(s)',
                        (int) ($progress['remaining_tour_days'] ?? 0),
                    ),
                    'date' => $assignment->planned_signoff_at?->toDateString(),
                ];
            } elseif ($tourStatus === 'missing_tour_rule') {
                $hasTourWarning = true;
                $warnings[] = [
                    'code' => 'missing_tour_of_duty',
                    'severity' => 'warning',
                    'label' => 'Missing Tour of Duty',
                    'message' => 'No Tour of Duty was applied for this assignment',
                    'date' => null,
                ];
            } elseif ($tourStatus === 'missing_signoff') {
                $hasTourWarning = true;
                $warnings[] = [
                    'code' => 'missing_planned_signoff',
                    'severity' => 'warning',
                    'label' => 'Missing Planned Sign-Off',
                    'message' => 'Active On Vessel assignment has no Planned Sign-Off',
                    'date' => null,
                ];
            }

            // Prefer Tour-specific warnings over the generic planned_signoff_overdue.
            if ($hasTourWarning) {
                $warnings = array_values(array_filter(
                    $warnings,
                    fn (array $warning): bool => $warning['code'] !== 'planned_signoff_overdue',
                ));
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
            ->with(['currentPhase', 'company', 'phases'])
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
