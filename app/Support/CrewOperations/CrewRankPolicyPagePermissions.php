<?php

namespace App\Support\CrewOperations;

use App\Models\User;

final class CrewRankPolicyPagePermissions
{
    /**
     * @return array{view: bool, update: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('crew_operations.rank_policies.view') ?? false,
            'update' => $user?->can('crew_operations.rank_policies.update') ?? false,
        ];
    }
}
