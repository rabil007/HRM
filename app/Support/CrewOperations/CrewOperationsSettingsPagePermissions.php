<?php

namespace App\Support\CrewOperations;

use App\Models\User;

final class CrewOperationsSettingsPagePermissions
{
    /**
     * @return array{update: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'update' => $user?->can('crew_operations.settings.update') ?? false,
        ];
    }
}
