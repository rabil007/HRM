<?php

namespace App\Support\SeaServices;

use App\Models\User;

final class SeaServicePagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool, import: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('sea_services.view') ?? false,
            'create' => $user?->can('sea_services.create') ?? false,
            'update' => $user?->can('sea_services.update') ?? false,
            'delete' => $user?->can('sea_services.delete') ?? false,
            'import' => $user?->can('sea_services.import') ?? false,
        ];
    }
}
