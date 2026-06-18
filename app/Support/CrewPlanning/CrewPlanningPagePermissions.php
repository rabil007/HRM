<?php

namespace App\Support\CrewPlanning;

use App\Models\User;

final class CrewPlanningPagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool, confirm: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('crew_operations.planning.view') ?? false,
            'create' => $user?->can('crew_operations.planning.create') ?? false,
            'update' => $user?->can('crew_operations.planning.update') ?? false,
            'delete' => $user?->can('crew_operations.planning.delete') ?? false,
            'confirm' => $user?->can('crew_operations.planning.confirm') ?? false,
        ];
    }
}
