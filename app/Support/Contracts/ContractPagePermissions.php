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
     *     salary_revisions_view: bool,
     *     salary_revisions_create: bool,
     *     salary_revisions_update: bool,
     *     salary_revisions_delete: bool
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
            'salary_revisions_view' => $user?->can('contracts.salary_revisions.view') ?? false,
            'salary_revisions_create' => $user?->can('contracts.salary_revisions.create') ?? false,
            'salary_revisions_update' => $user?->can('contracts.salary_revisions.update') ?? false,
            'salary_revisions_delete' => $user?->can('contracts.salary_revisions.delete') ?? false,
        ];
    }
}
