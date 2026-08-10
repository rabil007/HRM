<?php

namespace App\Support\CrewPlanning;

use App\Models\User;

final class CrewPlanningPagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool, projection: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('crew_operations.planning.view') ?? false,
            'create' => $user?->can('crew_operations.planning.create') ?? false,
            'update' => $user?->can('crew_operations.planning.update') ?? false,
            'delete' => $user?->can('crew_operations.planning.delete') ?? false,
            'projection' => $user?->can('crew_operations.vessel_manning.view') ?? false,
            'create_assignment' => $user?->can('crew_operations.assignments.create') ?? false,
        ];

    }
}
