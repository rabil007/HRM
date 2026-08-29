<?php

namespace App\Support\Documents\Signing;

use App\Models\User;

final class DocumentSigningPresetPagePermissions
{
    /**
     * @return array{view: bool, create: bool, update: bool, delete: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('documents.signing-presets.view') ?? false,
            'create' => $user?->can('documents.signing-presets.create') ?? false,
            'update' => $user?->can('documents.signing-presets.update') ?? false,
            'delete' => $user?->can('documents.signing-presets.delete') ?? false,
        ];
    }
}
