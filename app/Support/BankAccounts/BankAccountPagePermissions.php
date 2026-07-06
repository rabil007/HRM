<?php

namespace App\Support\BankAccounts;

use App\Models\User;

final class BankAccountPagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool, import: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('bank_accounts.view') ?? false,
            'create' => $user?->can('bank_accounts.create') ?? false,
            'update' => $user?->can('bank_accounts.update') ?? false,
            'delete' => $user?->can('bank_accounts.delete') ?? false,
            'import' => $user?->can('bank_accounts.import') ?? false,
        ];
    }
}
