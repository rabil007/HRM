<?php

namespace App\Support\Vessels;

use App\Models\User;

final class VesselPagePermissions
{
    /**
     * @return array{
     *     create: bool,
     *     update: bool,
     *     delete: bool,
     *     export: bool,
     *     import: bool,
     *     view_manning: bool,
     *     view_assignments: bool,
     *     view_planning: bool
     * }
     */
    public static function for(?User $user): array
    {
        $canCreate = $user?->can('crew_operations.vessels.create') ?? false;
        $canUpdate = $user?->can('crew_operations.vessels.update') ?? false;

        return [
            'create' => $canCreate,
            'update' => $canUpdate,
            'delete' => $user?->can('crew_operations.vessels.delete') ?? false,
            'export' => $user?->can('crew_operations.vessels.view') ?? false,
            'import' => $canCreate || $canUpdate,
            'view_manning' => $user?->can('crew_operations.vessel_manning.view') ?? false,
            'view_assignments' => $user?->can('crew_operations.assignments.view') ?? false,
            'view_planning' => $user?->can('crew_operations.planning.view') ?? false,
        ];
    }
}
