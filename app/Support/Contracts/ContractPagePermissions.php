<?php

namespace App\Support\Contracts;

use App\Models\User;

final class ContractPagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('contracts.view') ?? false,
            'create' => $user?->can('contracts.create') ?? false,
            'update' => $user?->can('contracts.update') ?? false,
            'delete' => $user?->can('contracts.delete') ?? false,
        ];
    }
}
