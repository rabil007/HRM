<?php

namespace App\Support\Reports;

use App\Models\User;

final class LeaveReportPagePermissions
{
    /**
     * @return array{export: bool, view_employee: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'export' => $user?->can('reports.leave.export') ?? false,
            'view_employee' => $user?->can('employees.view') ?? false,
        ];
    }
}
