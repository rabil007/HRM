<?php

namespace App\Support\CrewMovements\Corrections;

use App\Models\User;

final class CrewMovementCorrectionPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     request: bool,
     *     approve: bool,
     *     override: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('crew_operations.corrections.view') ?? false,
            'request' => $user?->can('crew_operations.corrections.request') ?? false,
            'approve' => $user?->can('crew_operations.corrections.approve') ?? false,
            'override' => $user?->can('crew_operations.corrections.override') ?? false,
        ];
    }
}
