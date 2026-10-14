<?php

namespace App\Support\EmployeeTrainings;

use App\Models\User;

class TrainingCertificateDocumentPermissions
{
    public static function assertCanUploadOrLink(?User $user): void
    {
        abort_unless(
            $user instanceof User
            && $user->can('documents.upload')
            && ($user->can('training.create') || $user->can('training.update')),
            403,
        );
    }

    public static function assertCanReplace(?User $user): void
    {
        abort_unless(
            $user instanceof User
            && $user->can('documents.upload')
            && $user->can('training.update'),
            403,
        );
    }

    public static function canManageCertificates(?User $user): bool
    {
        return $user instanceof User
            && $user->can('documents.upload')
            && ($user->can('training.create') || $user->can('training.update'));
    }
}
