<?php

namespace App\Support\EmployeeDocuments;

use App\Models\User;

class DocumentPagePermissions
{
    /**
     * @return array{download: bool, delete: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'download' => $user?->can('documents.download') ?? false,
            'delete' => $user?->can('documents.delete') ?? false,
        ];
    }
}
