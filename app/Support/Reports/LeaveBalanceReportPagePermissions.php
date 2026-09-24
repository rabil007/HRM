<?php

namespace App\Support\Reports;

use App\Models\User;

final class LeaveBalanceReportPagePermissions
{
    /**
     * @return array{export: bool, update_opening: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'export' => $user?->can('reports.leave_balance.export') ?? false,
            'update_opening' => $user?->can('reports.leave_balance.update_opening') ?? false,
        ];
    }
}
