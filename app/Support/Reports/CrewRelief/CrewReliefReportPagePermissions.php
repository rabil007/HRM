<?php

namespace App\Support\Reports\CrewRelief;

use App\Models\User;

final class CrewReliefReportPagePermissions
{
    /**
     * @return array{
     *     export: bool,
     *     view_assignments: bool,
     *     view_employees: bool,
     *     view_vessels: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'export' => $user?->can('reports.crew_relief.export') ?? false,
            'view_assignments' => $user?->can('crew_operations.assignments.view') ?? false,
            'view_employees' => $user?->can('employees.view') ?? false,
            'view_vessels' => $user?->can('crew_operations.vessels.view') ?? false,
        ];
    }
}
