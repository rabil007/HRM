<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CrewAssignmentPagePermissions
{
    /**
     * Transfer Vessel is opened from the source assignment page, so the
     * recommendation action requires both the movement and the assignment view.
     */
    public static function canTransfer(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can('crew_operations.movements.perform')
            && $user->can('crew_operations.assignments.view');
    }

    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     create_historical: bool,
     *     start: bool,
     *     update: bool,
     *     perform_movement: bool,
     *     schedule_movement: bool,
     *     manage_scheduled_movement: bool,
     *     view_scheduled_movements: bool,
     *     cancel: bool,
     *     void: bool,
     *     view_audit: bool,
     *     request_correction: bool,
     *     view_corrections: bool,
     *     approve_corrections: bool,
     *     override_corrections: bool,
     *     view_documents: bool,
     *     view_training: bool,
     *     view_planning: bool,
     *     view_employee: bool,
     *     delete_sea_service: bool,
     *     delete_training: bool
     * }
     */
    public static function for(?User $user): array
    {
        $create = $user?->can('crew_operations.assignments.create') ?? false;
        $performMovement = $user?->can('crew_operations.movements.perform') ?? false;

        return [
            'view' => $user?->can('crew_operations.assignments.view') ?? false,
            'create' => $create,
            'create_historical' => $user?->can('crew_operations.assignments.create_historical') ?? false,
            'start' => $create && $performMovement,
            'update' => $user?->can('crew_operations.assignments.update') ?? false,
            'perform_movement' => $performMovement,
            'schedule_movement' => $user?->can('crew_operations.movements.schedule') ?? false,
            'manage_scheduled_movement' => $user?->can('crew_operations.movements.schedule.manage') ?? false,
            'view_scheduled_movements' => $user?->can('crew_operations.movements.schedule.view') ?? false,
            'cancel' => $user?->can('crew_operations.assignments.cancel') ?? false,
            'void' => $user?->can('crew_operations.assignments.void') ?? false,
            'view_audit' => $user?->can('audit.view') ?? false,
            'request_correction' => $user?->can('crew_operations.corrections.request') ?? false,
            'view_corrections' => $user?->can('crew_operations.corrections.view') ?? false,
            'approve_corrections' => $user?->can('crew_operations.corrections.approve') ?? false,
            'override_corrections' => $user?->can('crew_operations.corrections.override') ?? false,
            'view_documents' => $user?->can('documents.view') ?? false,
            'view_training' => $user?->can('training.view') ?? false,
            'view_planning' => $user?->can('crew_operations.planning.view') ?? false,
            'view_employee' => $user?->can('employees.view') ?? false,
            'delete_sea_service' => $user?->can('sea_services.delete') ?? false,
            'delete_training' => $user?->can('training.delete') ?? false,
        ];
    }

    /**
     * Instance-aware permissions for a specific operational CrewAssignment.
     *
     * @return array{
     *     view: bool,
     *     create: bool,
     *     create_historical: bool,
     *     start: bool,
     *     update: bool,
     *     perform_movement: bool,
     *     schedule_movement: bool,
     *     manage_scheduled_movement: bool,
     *     view_scheduled_movements: bool,
     *     cancel: bool,
     *     void: bool,
     *     view_audit: bool,
     *     request_correction: bool,
     *     view_corrections: bool,
     *     approve_corrections: bool,
     *     override_corrections: bool,
     *     view_documents: bool,
     *     view_training: bool,
     *     view_planning: bool,
     *     view_employee: bool,
     *     delete_sea_service: bool,
     *     delete_training: bool
     * }
     */
    public static function forAssignment(?User $user, CrewAssignment $assignment): array
    {
        $base = self::for($user);

        if ($user === null) {
            return $base;
        }

        $base['view'] = Gate::forUser($user)->allows('view', $assignment);
        $base['update'] = Gate::forUser($user)->allows('update', $assignment);
        $base['cancel'] = Gate::forUser($user)->allows('cancel', $assignment);
        $base['perform_movement'] = Gate::forUser($user)->allows('performMovement', $assignment);
        $base['schedule_movement'] = Gate::forUser($user)->allows('scheduleMovement', $assignment);
        $base['manage_scheduled_movement'] = Gate::forUser($user)->allows('manageScheduledMovement', $assignment);
        $base['void'] = Gate::forUser($user)->allows('void', $assignment);

        return $base;
    }
}
