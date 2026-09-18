<?php

namespace App\Support\Employees;

use App\Models\User;

final class EmployeePagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool, export: bool, import: bool, manage_deleted: bool}
     */
    public static function for(?User $user): array
    {
        $canView = $user?->can('employees.view') ?? false;
        $canDelete = $user?->can('employees.delete') ?? false;

        return [
            'view' => $canView,
            'create' => $user?->can('employees.create') ?? false,
            'update' => $user?->can('employees.update') ?? false,
            'delete' => $canDelete,
            'export' => $user?->can('employees.export') ?? false,
            'import' => $user?->can('employees.import') ?? false,
            'manage_deleted' => $canView && $canDelete,
        ];
    }
}
