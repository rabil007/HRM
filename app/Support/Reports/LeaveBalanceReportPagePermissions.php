<?php

namespace App\Support\Reports;

use App\Models\User;

final class LeaveBalanceReportPagePermissions
{
    /**
     * @return array{export: bool, update_opening: bool, sync_missing: bool}
     */
    public static function for(?User $user): array
    {
        $canView = $user?->can('reports.leave_balance.view') ?? false;

        return [
            'export' => $user?->can('reports.leave_balance.export') ?? false,
            'update_opening' => $user?->can('reports.leave_balance.update_opening') ?? false,
            'sync_missing' => $canView && ($user?->can('reports.leave_balance.sync') ?? false),
        ];
    }
}
