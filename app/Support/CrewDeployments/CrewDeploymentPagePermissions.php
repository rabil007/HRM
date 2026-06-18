<?php

namespace App\Support\CrewDeployments;

use App\Models\User;

final class CrewDeploymentPagePermissions
{
    /**
     * @return array{create: bool, update: bool, delete: bool, export: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'create' => $user?->can('crew_operations.deployments.create') ?? false,
            'update' => $user?->can('crew_operations.deployments.update') ?? false,
            'delete' => $user?->can('crew_operations.deployments.delete') ?? false,
            'export' => $user?->can('crew_operations.deployments.export') ?? false,
        ];
    }
}
