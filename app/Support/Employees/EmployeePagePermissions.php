<?php

namespace App\Support\Employees;

use App\Models\User;

final class EmployeePagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool, export: bool, import: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('employees.view') ?? false,
            'create' => $user?->can('employees.create') ?? false,
            'update' => $user?->can('employees.update') ?? false,
            'delete' => $user?->can('employees.delete') ?? false,
            'export' => $user?->can('employees.export') ?? false,
            'import' => $user?->can('employees.import') ?? false,
        ];
    }
}
