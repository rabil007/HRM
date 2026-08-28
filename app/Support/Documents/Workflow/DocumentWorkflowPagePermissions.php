<?php

namespace App\Support\Documents\Workflow;

use App\Models\User;

final class DocumentWorkflowPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     review: bool,
     *     approve: bool,
     *     cancel: bool,
     *     view_signatures: bool,
     *     review_signatures: bool,
     *     view_recipient_requests: bool,
     *     create_recipient_requests: bool,
     *     cancel_recipient_requests: bool,
     *     respond_recipient_requests: bool,
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('documents.requests.view') ?? false,
            'create' => $user?->can('documents.requests.create') ?? false,
            'review' => $user?->can('documents.requests.review') ?? false,
            'approve' => $user?->can('documents.requests.approve') ?? false,
            'cancel' => $user?->can('documents.requests.cancel') ?? false,
            'view_signatures' => $user?->can('bulk_documents.view') ?? false,
            'review_signatures' => $user?->can('bulk_documents.signatures.review') ?? false,
            'view_recipient_requests' => $user?->can('documents.recipient-requests.view') ?? false,
            'create_recipient_requests' => $user?->can('documents.recipient-requests.create') ?? false,
            'cancel_recipient_requests' => $user?->can('documents.recipient-requests.cancel') ?? false,
            'respond_recipient_requests' => $user?->can('documents.recipient-requests.respond') ?? false,
        ];
    }
}
