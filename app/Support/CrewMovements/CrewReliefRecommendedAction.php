<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Models\User;

final class CrewReliefRecommendedAction
{
    /**
     * @return array{key: string, label: string, href: string|null}
     */
    public static function for(
        CrewAssignment $source,
        CrewReliefReadinessResult $relief,
        User $user,
        bool $reliefEmployeeVisible = true,
    ): array {
        $canViewAssignments = $user->can('crew_operations.assignments.view');
        $canCreatePlanning = $user->can('crew_operations.planning.create');

        if (! $reliefEmployeeVisible) {
            return match ($relief->status) {
                CrewReliefStatus::NoRelief => [
                    'key' => 'plan_relief',
                    'label' => 'Plan Relief',
                    'href' => $canCreatePlanning ? self::planReliefHref($source) : null,
                ],
                CrewReliefStatus::ReliefPlanned => [
                    'key' => 'open_relief_plan',
                    'label' => 'Open Relief Plan',
                    'href' => null,
                ],
                CrewReliefStatus::AssignmentCreated,
                CrewReliefStatus::Mobilising => [
                    'key' => 'open_relief_assignment',
                    'label' => 'Open Relief Assignment',
                    'href' => null,
                ],
                CrewReliefStatus::ReadyToJoin => [
                    'key' => 'open_assignment',
                    'label' => 'Open Assignment',
                    'href' => null,
                ],
                CrewReliefStatus::ReliefOnboard => [
                    'key' => 'open_current_assignment',
                    'label' => 'Open Current Assignment',
                    'href' => $canViewAssignments
                        ? route('organization.crew-assignments.show', $source)
                        : null,
                ],
            };
        }

        return match ($relief->status) {
            CrewReliefStatus::NoRelief => [
                'key' => 'plan_relief',
                'label' => 'Plan Relief',
                'href' => $canCreatePlanning ? self::planReliefHref($source) : null,
            ],
            CrewReliefStatus::ReliefPlanned => [
                'key' => 'open_relief_plan',
                'label' => 'Open Relief Plan',
                'href' => self::openReliefPlanHref($source, $relief),
            ],
            CrewReliefStatus::AssignmentCreated,
            CrewReliefStatus::Mobilising,
            CrewReliefStatus::ReadyToJoin => [
                'key' => 'open_relief_assignment',
                'label' => 'Open Relief Assignment',
                'href' => $canViewAssignments && $relief->reliefCrewAssignmentId !== null
                    ? route('organization.crew-assignments.show', $relief->reliefCrewAssignmentId)
                    : null,
            ],
            CrewReliefStatus::ReliefOnboard => [
                'key' => 'open_current_assignment',
                'label' => 'Open Current Assignment',
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.show', $source)
                    : null,
            ],
        };
    }

    public static function planReliefHref(CrewAssignment $source): string
    {
        return route('organization.crew-planning.index', array_filter([
            'vessel_id' => $source->vessel_id,
            'position_id' => $source->position_id,
            'relieves_crew_assignment_id' => $source->id,
            'planned_join_date' => $source->planned_signoff_at?->toDateString(),
            'open_create' => 1,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    public static function openReliefPlanHref(CrewAssignment $source, CrewReliefReadinessResult $relief): string
    {
        $params = [
            'vessel_id' => $source->vessel_id,
            'position_id' => $source->position_id,
        ];

        if ($relief->reliefPlanningAssignmentId !== null) {
            $params['planning_assignment_id'] = $relief->reliefPlanningAssignmentId;
        } elseif ($relief->reliefEmployee !== null) {
            $params['search'] = $relief->reliefEmployee['name'] ?? null;
        }

        return route('organization.crew-planning.index', array_filter(
            $params,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }
}
