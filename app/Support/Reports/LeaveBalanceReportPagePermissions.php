<?php

namespace App\Support\Reports;

use App\Models\User;

final class LeaveBalanceReportPagePermissions
{
    /**
     * @return array{export: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'export' => $user?->can('reports.leave_balance.export') ?? false,
        ];
    }
}
