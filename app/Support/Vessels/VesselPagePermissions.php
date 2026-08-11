<?php

namespace App\Support\Vessels;

use App\Models\User;

final class VesselPagePermissions
{
    /**
     * @return array{create: bool, update: bool, delete: bool, view_manning: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'create' => $user?->can('crew_operations.vessels.create') ?? false,
            'update' => $user?->can('crew_operations.vessels.update') ?? false,
            'delete' => $user?->can('crew_operations.vessels.delete') ?? false,
            'view_manning' => $user?->can('crew_operations.vessel_manning.view') ?? false,
        ];
    }
}
