<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\User;

final class DocumentRecipientRequestPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     cancel: bool,
     *     respond: bool,
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('documents.recipient-requests.view') ?? false,
            'create' => $user?->can('documents.recipient-requests.create') ?? false,
            'cancel' => $user?->can('documents.recipient-requests.cancel') ?? false,
            'respond' => $user?->can('documents.recipient-requests.respond') ?? false,
        ];
    }
}
