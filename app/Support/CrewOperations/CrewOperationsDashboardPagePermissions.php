<?php

namespace App\Support\CrewOperations;

use App\Models\User;

final class CrewOperationsDashboardPagePermissions
{
    /**
     * @return array{
     *     planning: bool,
     *     vessel_manning: bool,
     *     deployments: bool,
     *     deployments_create: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'planning' => $user?->can('crew_operations.planning.view') ?? false,
            'vessel_manning' => $user?->can('crew_operations.vessel_manning.view') ?? false,
            'deployments' => $user?->can('crew_operations.deployments.view') ?? false,
            'deployments_create' => $user?->can('crew_operations.deployments.create') ?? false,
        ];
    }
}
