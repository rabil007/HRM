<?php

namespace App\Support\CrewOperations;

use App\Models\User;

final class CrewOperationsDashboardPagePermissions
{
    /**
     * @return array{
     *     overview: bool,
     *     planning: bool,
     *     vessel_manning: bool,
     *     deployments: bool,
     *     deployments_create: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'overview' => CrewOperationsOverviewAccess::canView($user),
            'planning' => $user?->can('crew_operations.planning.view') ?? false,
            'vessel_manning' => $user?->can('crew_operations.vessel_manning.view') ?? false,
            'deployments' => false,
            'deployments_create' => false,
            'corrections_view' => $user?->can('crew_operations.corrections.view') ?? false,
            'corrections_approve' => $user?->can('crew_operations.corrections.approve') ?? false,
        ];
    }
}
