<?php

namespace App\Support\EmployeeTrainings;

use App\Models\User;

class TrainingPagePermissions
{
    /**
     * @return array{manage: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'manage' => $user?->can('employees.training.manage') ?? false,
        ];
    }
}
