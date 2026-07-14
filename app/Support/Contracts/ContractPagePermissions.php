<?php

namespace App\Support\Contracts;

use App\Models\User;

final class ContractPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     delete: bool,
     *     import: bool,
     *     manage_salary_revisions: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('contracts.view') ?? false,
            'create' => $user?->can('contracts.create') ?? false,
            'update' => $user?->can('contracts.update') ?? false,
            'delete' => $user?->can('contracts.delete') ?? false,
            'import' => $user?->can('contracts.import') ?? false,
            'manage_salary_revisions' => $user?->can('contracts.salary_revisions.manage') ?? false,
        ];
    }
}
