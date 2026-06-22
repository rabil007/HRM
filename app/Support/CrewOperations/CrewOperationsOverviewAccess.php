<?php

namespace App\Support\CrewOperations;

use App\Models\User;

final class CrewOperationsOverviewAccess
{
    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can('crew_operations.overview.view')
            || $user->can('crew_operations.deployments.view');
    }

    public static function assertCanView(?User $user): void
    {
        if (! self::canView($user)) {
            abort(403);
        }
    }
}
