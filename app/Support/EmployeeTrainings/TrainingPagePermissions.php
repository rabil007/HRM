<?php

namespace App\Support\EmployeeTrainings;

use App\Models\User;

class TrainingPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     delete: bool,
     *     import: bool,
     *     manage_certificate_documents: bool,
     *     view_documents: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('training.view') ?? false,
            'create' => $user?->can('training.create') ?? false,
            'update' => $user?->can('training.update') ?? false,
            'delete' => $user?->can('training.delete') ?? false,
            'import' => $user?->can('training.import') ?? false,
            'manage_certificate_documents' => TrainingCertificateDocumentPermissions::canManageCertificates($user),
            'view_documents' => $user?->can('documents.view') ?? false,
        ];
    }
}
