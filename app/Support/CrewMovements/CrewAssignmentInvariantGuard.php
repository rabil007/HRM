<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;

class CrewAssignmentInvariantGuard
{
    public function assertValid(CrewAssignment $assignment): void
    {
        $assignment->loadMissing([
            'employee',
            'phases',
            'currentPhase',
            'previousAssignment',
            'planningAssignment',
        ]);

        $this->assertCompanyIntegrity($assignment);
        $this->assertEmployeeIntegrity($assignment);
        $this->assertCurrentPhaseIntegrity($assignment);
        $this->assertPhaseIntegrity($assignment);
        $this->assertAssignmentIntegrity($assignment);
    }

    private function assertCompanyIntegrity(CrewAssignment $assignment): void
    {
        if ($assignment->employee !== null
            && (int) $assignment->employee->company_id !== (int) $assignment->company_id) {
            throw CrewMovementException::make(
                'Assignment company does not match employee company.',
                'company_mismatch_employee',
            );
        }

        foreach ($assignment->phases as $phase) {
            if ((int) $phase->company_id !== (int) $assignment->company_id) {
                throw CrewMovementException::make(
                    'Phase company does not match assignment company.',
                    'company_mismatch_phase',
                );
            }
        }

        if ($assignment->currentPhase !== null
            && (int) $assignment->currentPhase->company_id !== (int) $assignment->company_id) {
            throw CrewMovementException::make(
                'Current phase company does not match assignment company.',
                'company_mismatch_current_phase',
            );
        }

        if ($assignment->previousAssignment !== null
            && (int) $assignment->previousAssignment->company_id !== (int) $assignment->company_id) {
            throw CrewMovementException::make(
                'Previous assignment company does not match assignment company.',
                'company_mismatch_previous',
            );
        }

        if ($assignment->planningAssignment !== null
            && (int) $assignment->planningAssignment->company_id !== (int) $assignment->company_id) {
            throw CrewMovementException::make(
                'Linked planning assignment company does not match assignment company.',
                'company_mismatch_planning',
            );
        }
    }

    private function assertEmployeeIntegrity(CrewAssignment $assignment): void
    {
        if ($assignment->previousAssignment !== null
            && (int) $assignment->previousAssignment->employee_id !== (int) $assignment->employee_id) {
            throw CrewMovementException::make(
                'Previous assignment belongs to a different employee.',
                'employee_mismatch_previous',
            );
        }

        if ($assignment->planningAssignment !== null
            && $assignment->planningAssignment->employee_id !== null
            && (int) $assignment->planningAssignment->employee_id !== (int) $assignment->employee_id) {
            throw CrewMovementException::make(
                'Linked planning assignment belongs to a different employee.',
                'employee_mismatch_planning',
            );
        }
    }

    private function assertCurrentPhaseIntegrity(CrewAssignment $assignment): void
    {
        if ($assignment->current_phase_id === null) {
            return;
        }

        $current = $assignment->currentPhase;

        if ($current === null) {
            throw CrewMovementException::make(
                'Current phase is missing or soft-deleted.',
                'current_phase_missing',
            );
        }

        if ((int) $current->crew_assignment_id !== (int) $assignment->id) {
            throw CrewMovementException::make(
                'Current phase does not belong to this assignment.',
                'current_phase_wrong_assignment',
            );
        }

        if ((int) $current->company_id !== (int) $assignment->company_id) {
            throw CrewMovementException::make(
                'Current phase company does not match assignment company.',
                'current_phase_company_mismatch',
            );
        }

        if ($assignment->status === CrewAssignmentStatus::Draft) {
            if (! in_array($current->status, [CrewPhaseStatus::Planned, CrewPhaseStatus::Active], true)) {
                throw CrewMovementException::make(
                    'Draft assignment current phase must be planned or active.',
                    'draft_current_phase_status',
                );
            }

            return;
        }

        if ($assignment->status === CrewAssignmentStatus::Active
            && $current->status !== CrewPhaseStatus::Active) {
            throw CrewMovementException::make(
                'Active assignment current phase must be active.',
                'active_current_phase_status',
            );
        }
    }

    private function assertPhaseIntegrity(CrewAssignment $assignment): void
    {
        $sequences = [];
        $activeCount = 0;

        foreach ($assignment->phases as $phase) {
            if (isset($sequences[$phase->sequence])) {
                throw CrewMovementException::make(
                    'Phase sequence must be unique within an assignment.',
                    'phase_sequence_duplicate',
                );
            }
            $sequences[$phase->sequence] = true;

            if ($phase->actual_start_at !== null
                && $phase->actual_end_at !== null
                && $phase->actual_end_at->lt($phase->actual_start_at)) {
                throw CrewMovementException::make(
                    'Phase actual end cannot be before actual start.',
                    'phase_actual_range',
                );
            }

            if ($phase->planned_start_at !== null
                && $phase->planned_end_at !== null
                && $phase->planned_end_at->lt($phase->planned_start_at)) {
                throw CrewMovementException::make(
                    'Phase planned end cannot be before planned start.',
                    'phase_planned_range',
                );
            }

            if ($phase->status === CrewPhaseStatus::Active) {
                $activeCount++;
            }
        }

        if ($activeCount > 1) {
            throw CrewMovementException::make(
                'An assignment cannot have more than one active phase.',
                'multiple_active_phases',
            );
        }
    }

    private function assertAssignmentIntegrity(CrewAssignment $assignment): void
    {
        if ($assignment->status === CrewAssignmentStatus::Completed && $assignment->closed_at === null) {
            throw CrewMovementException::make(
                'Completed assignment must have closed_at.',
                'completed_missing_closed_at',
            );
        }

        if ($assignment->status === CrewAssignmentStatus::Cancelled && $assignment->closed_at === null) {
            throw CrewMovementException::make(
                'Cancelled assignment must have closed_at.',
                'cancelled_missing_closed_at',
            );
        }

        if ($assignment->status === CrewAssignmentStatus::Active && $assignment->started_at === null) {
            throw CrewMovementException::make(
                'Active assignment must have started_at.',
                'active_missing_started_at',
            );
        }
    }
}
