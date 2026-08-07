<?php

namespace App\Support\CrewOperations;

use App\Models\User;

final class CrewProjectedManningPagePermissions
{
    /**
     * @return array{view: bool, plan_crew: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('crew_operations.vessel_manning.view') ?? false,
            'plan_crew' => $user?->can('crew_operations.planning.view') ?? false,
        ];
    }
}
