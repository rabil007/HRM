<?php

namespace App\Support\Reports;

use App\Models\User;

final class CrewMovementHistoryPagePermissions
{
    /**
     * @return array{export: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'export' => $user?->can('reports.crew_movement_history.export') ?? false,
        ];
    }
}
