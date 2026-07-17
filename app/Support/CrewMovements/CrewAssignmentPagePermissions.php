<?php

namespace App\Support\CrewMovements;

use App\Models\User;

class CrewAssignmentPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     perform_movement: bool,
     *     cancel: bool,
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
            'view_audit' => $user?->can('audit.view') ?? false,
            'request_correction' => $user?->can('crew_operations.corrections.request') ?? false,
            'view_corrections' => $user?->can('crew_operations.corrections.view') ?? false,
            'approve_corrections' => $user?->can('crew_operations.corrections.approve') ?? false,
            'override_corrections' => $user?->can('crew_operations.corrections.override') ?? false,
        ];
    }
}
