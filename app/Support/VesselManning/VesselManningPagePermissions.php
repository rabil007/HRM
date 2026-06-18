<?php

namespace App\Support\VesselManning;

use App\Models\User;

final class VesselManningPagePermissions
{
    /**
     * @return array{create: bool, update: bool, delete: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'create' => $user?->can('crew_operations.vessel_manning.create') ?? false,
            'update' => $user?->can('crew_operations.vessel_manning.update') ?? false,
            'delete' => $user?->can('crew_operations.vessel_manning.delete') ?? false,
        ];
    }

    public static function canWrite(?User $user): bool
    {
        $permissions = self::for($user);

        return $permissions['create'] || $permissions['update'] || $permissions['delete'];
    }
}
