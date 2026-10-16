<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\User;

final class CrewAssignmentRecommendedActionResolver
{
    /**
     * Choose one advisory next step from already-allowed actions (or a non-movement hint).
     *
     * @param  list<string>  $availableActions
     */
    public function forAssignment(
        CrewAssignment $assignment,
        array $availableActions,
        ?CrewMobilisationReadinessResult $readiness = null,
        ?CrewReliefReadinessResult $relief = null,
        ?User $user = null,
    ): ?CrewAssignmentRecommendedActionResult {
        $permitted = $this->permittedActions($availableActions, $user);
        $phase = $assignment->currentPhase?->phase_code;

        if ($phase === CrewPhaseCode::PreArrival) {
            return $this->forPreArrival($permitted, $readiness);
        }

        if ($phase === CrewPhaseCode::JoinStandby) {
            return $this->firstMovement($permitted, [
                [CrewMovementAction::JoinVessel, 'Crew member is on join standby and can join the vessel.'],
                [CrewMovementAction::SendToTraining, 'Send the crew member to training if that is the operational path.'],
            ]);
        }

        if ($phase === CrewPhaseCode::Training) {
            return $this->movementRecommendation(
                $permitted,
                CrewMovementAction::CompleteTraining,
                'Record training completion when the course is finished.',
            );
        }

        if ($phase === CrewPhaseCode::OnVessel) {
            // Relief readiness stays in operator attention. It does not replace Confirm Disembarkation.
            unset($relief);

            return $this->forOnVessel($permitted);
        }

        if ($phase === CrewPhaseCode::DemobStandby) {
            return $this->firstMovement($permitted, [
                [CrewMovementAction::TravelHome, 'Crew member is on demobilisation standby. Record return home and close the assignment when the mobilisation cycle is complete.'],
                [CrewMovementAction::Redeploy, 'Redeploy if the next cycle is already being arranged.'],
            ]);
        }

        if ($phase === CrewPhaseCode::HomeRedeploy) {
            return $this->firstMovement($permitted, [
                [CrewMovementAction::CloseAssignment, 'The mobilisation cycle can be closed.'],
                [CrewMovementAction::Redeploy, 'Redeploy onto a new assignment if the next cycle is planned.'],
            ]);
        }

        return null;
    }

    /**
     * @param  list<string>  $permitted
     */
    private function forPreArrival(
        array $permitted,
        ?CrewMobilisationReadinessResult $readiness,
    ): ?CrewAssignmentRecommendedActionResult {
        $canRecordArrival = $this->allows($permitted, CrewMovementAction::RecordArrival);
        $canApprove = $this->allows($permitted, CrewMovementAction::ApproveMobilisation);
        $hasIssues = $readiness !== null
            && $readiness->applies
            && $readiness->status !== CrewMobilisationReadinessStatus::Ready;

        if ($hasIssues) {
            $problemCount = count($readiness->problems);

            // Advisory only — mobilisation is not blocked. Do not invent a second
            // "Anyway" button for the same Draft→Active / Record Arrival transition;
            // operators use the normal available action once.
            return new CrewAssignmentRecommendedActionResult(
                type: 'readiness',
                label: 'Resolve readiness issues before mobilisation',
                reason: $problemCount === 1
                    ? 'One mobilisation requirement needs attention. This is guidance only and does not block movement.'
                    : sprintf('%d mobilisation requirements need attention. This is guidance only and does not block movement.', $problemCount),
                href: $readiness->documentsHref,
            );
        }

        if ($canRecordArrival) {
            return $this->movementRecommendation(
                $permitted,
                CrewMovementAction::RecordArrival,
                'Crew member is in Pre-Arrival. Record arrival when they reach the join location.',
            );
        }

        if ($canApprove) {
            return $this->movementRecommendation(
                $permitted,
                CrewMovementAction::ApproveMobilisation,
                'Assignment is in draft. Start assignment when Operations is ready to proceed.',
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $permitted
     */
    private function forOnVessel(array $permitted): ?CrewAssignmentRecommendedActionResult
    {
        return $this->firstMovement($permitted, [
            [CrewMovementAction::ConfirmDisembarkation, 'Confirm disembarkation when the crew member actually leaves the vessel.'],
            [CrewMovementAction::PlanSignoff, 'Update the planned sign-off forecast. This does not disembark the crew member.'],
            [CrewMovementAction::TransferVessel, 'Transfer the crew member to another vessel without treating that as disembarkation home.'],
        ]);
    }

    /**
     * @param  list<string>  $permitted
     * @param  list<array{0: CrewMovementAction, 1: string}>  $candidates
     */
    private function firstMovement(array $permitted, array $candidates): ?CrewAssignmentRecommendedActionResult
    {
        foreach ($candidates as [$action, $reason]) {
            $result = $this->movementRecommendation($permitted, $action, $reason);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $permitted
     */
    private function movementRecommendation(
        array $permitted,
        CrewMovementAction $action,
        string $reason,
    ): ?CrewAssignmentRecommendedActionResult {
        if (! $this->allows($permitted, $action)) {
            return null;
        }

        return new CrewAssignmentRecommendedActionResult(
            type: 'movement',
            label: $action->label(),
            reason: $reason,
            action: $action->value,
        );
    }

    /**
     * @param  list<string>  $availableActions
     * @return list<string>
     */
    private function permittedActions(array $availableActions, ?User $user): array
    {
        if ($user === null) {
            return array_values(array_filter(
                $availableActions,
                fn (string $action): bool => $action !== CrewMovementAction::CancelAssignment->value,
            ));
        }

        $canPerform = $user->can('crew_operations.movements.perform');
        $canCancel = $user->can('crew_operations.assignments.cancel');

        return array_values(array_filter(
            $availableActions,
            function (string $action) use ($canPerform, $canCancel): bool {
                if ($action === CrewMovementAction::CancelAssignment->value) {
                    return $canCancel;
                }

                return $canPerform;
            },
        ));
    }

    /**
     * @param  list<string>  $permitted
     */
    private function allows(array $permitted, CrewMovementAction $action): bool
    {
        return in_array($action->value, $permitted, true);
    }
}
