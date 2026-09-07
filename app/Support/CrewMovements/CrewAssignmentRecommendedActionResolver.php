<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewReliefStatus;
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

        if ($phase === CrewPhaseCode::PreMobilisation) {
            return $this->forPreMobilisation($permitted, $readiness);
        }

        if ($phase === CrewPhaseCode::TravelIn) {
            return $this->movementRecommendation(
                $permitted,
                CrewMovementAction::RecordArrival,
                'Crew member is travelling in. Record arrival when they reach the join location.',
            );
        }

        if ($phase === CrewPhaseCode::JoinStandby) {
            return $this->firstMovement($permitted, [
                [CrewMovementAction::JoinVessel, 'Crew member is on join standby and can join the vessel.'],
                [CrewMovementAction::MarkReady, 'Crew member can be marked ready to join.'],
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

        if ($phase === CrewPhaseCode::ReadyToJoin) {
            return $this->movementRecommendation(
                $permitted,
                CrewMovementAction::JoinVessel,
                'Crew member is ready to join the vessel.',
            );
        }

        if ($phase === CrewPhaseCode::OnVessel) {
            return $this->forOnVessel($assignment, $permitted, $relief, $user);
        }

        if ($phase === CrewPhaseCode::DemobStandby) {
            return $this->firstMovement($permitted, [
                [CrewMovementAction::TravelHome, 'Crew member is on demobilisation standby. Record travel home when they depart.'],
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
    private function forPreMobilisation(
        array $permitted,
        ?CrewMobilisationReadinessResult $readiness,
    ): ?CrewAssignmentRecommendedActionResult {
        $canApprove = $this->allows($permitted, CrewMovementAction::ApproveMobilisation);
        $hasIssues = $readiness !== null
            && $readiness->applies
            && $readiness->status !== CrewMobilisationReadinessStatus::Ready;

        if ($hasIssues) {
            $problemCount = count($readiness->problems);

            return new CrewAssignmentRecommendedActionResult(
                type: 'readiness',
                label: 'Resolve readiness issues before mobilisation',
                reason: $problemCount === 1
                    ? 'One mobilisation requirement needs attention. This is guidance only and does not block movement.'
                    : sprintf('%d mobilisation requirements need attention. This is guidance only and does not block movement.', $problemCount),
                href: $readiness->documentsHref,
                anywayAction: $canApprove ? CrewMovementAction::ApproveMobilisation->value : null,
                anywayLabel: $canApprove ? 'Approve Mobilisation Anyway' : null,
            );
        }

        $reason = $readiness !== null && $readiness->applies && ! $readiness->hasConfiguredChecks()
            ? 'No required document checks are configured. Approve mobilisation when Operations is ready to proceed.'
            : 'Readiness looks clear. Approve mobilisation when Operations is ready to proceed.';

        return $this->movementRecommendation(
            $permitted,
            CrewMovementAction::ApproveMobilisation,
            $reason,
        );
    }

    /**
     * @param  list<string>  $permitted
     */
    private function forOnVessel(
        CrewAssignment $assignment,
        array $permitted,
        ?CrewReliefReadinessResult $relief,
        ?User $user,
    ): ?CrewAssignmentRecommendedActionResult {
        $daysUntil = $relief?->daysUntilSignoff;
        $reliefNotReady = $relief !== null && in_array($relief->status, CrewReliefStatus::notReady(), true);
        $signoffSoon = $daysUntil !== null && $daysUntil <= 14;

        if ($reliefNotReady && $signoffSoon && $user?->can('crew_operations.planning.view')) {
            return new CrewAssignmentRecommendedActionResult(
                type: 'relief',
                label: $relief->status->actionLabel(),
                reason: $relief->status === CrewReliefStatus::NoRelief
                    ? 'Planned sign-off is approaching and no operational relief is in place.'
                    : 'Planned sign-off is approaching and the planned relief is not ready to join.',
                href: $this->reliefHref($assignment, $relief),
            );
        }

        return $this->firstMovement($permitted, [
            [CrewMovementAction::ConfirmDisembarkation, 'Confirm disembarkation when the crew member actually leaves the vessel.'],
            [CrewMovementAction::PlanSignoff, 'Update the planned sign-off forecast. This does not disembark the crew member.'],
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

    private function reliefHref(CrewAssignment $assignment, CrewReliefReadinessResult $relief): string
    {
        if ($relief->status === CrewReliefStatus::NoRelief) {
            return route('organization.crew-planning.index', array_filter([
                'vessel_id' => $assignment->vessel_id,
                'rank_id' => $assignment->rank_id,
                'relieves_crew_assignment_id' => $assignment->id,
                'planned_join_date' => $assignment->planned_signoff_at?->toDateString(),
                'open_create' => 1,
            ], fn ($value) => $value !== null && $value !== ''));
        }

        if ($relief->reliefCrewAssignmentId !== null) {
            return route('organization.crew-assignments.show', $relief->reliefCrewAssignmentId);
        }

        return route('organization.crew-planning.index', array_filter([
            'vessel_id' => $assignment->vessel_id,
            'rank_id' => $assignment->rank_id,
        ], fn ($value) => $value !== null));
    }
}
