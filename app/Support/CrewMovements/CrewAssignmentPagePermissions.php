<?php

namespace App\Support\CrewMovements;

use App\Models\User;

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
     *     update: bool,
     *     perform_movement: bool,
     *     cancel: bool,
     *     void: bool,
     *     view_audit: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('crew_operations.assignments.view') ?? false,
            'create' => $user?->can('crew_operations.assignments.create') ?? false,
            'update' => $user?->can('crew_operations.assignments.update') ?? false,
            'perform_movement' => $user?->can('crew_operations.movements.perform') ?? false,
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
        ];
    }
}
