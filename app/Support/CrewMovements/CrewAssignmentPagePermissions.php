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
        ];
    }
}
