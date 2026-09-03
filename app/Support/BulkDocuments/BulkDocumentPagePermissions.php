<?php

namespace App\Support\BulkDocuments;

use App\Models\User;

final class BulkDocumentPagePermissions
{
    /**
     * @return array{
     *     generate: bool,
     *     download: bool,
     *     delete: bool,
     *     email: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'generate' => $user?->can('bulk_documents.generate') ?? false,
            'download' => $user?->can('documents.download') ?? false,
            'delete' => $user?->can('bulk_documents.delete') ?? false,
            'email' => $user?->can('bulk_documents.email') ?? false,
        ];
    }
}
