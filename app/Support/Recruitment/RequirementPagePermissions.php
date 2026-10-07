<?php

namespace App\Support\Recruitment;

use App\Models\User;

final class RequirementPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     submit: bool,
     *     approve: bool,
     *     close: bool,
     *     cancel: bool,
     *     reopen: bool,
     *     download_attachments: bool,
     *     repeat: bool,
     *     view_audit: bool,
     *     request_deadline_extension: bool,
     *     request_headcount_revision: bool,
     * }
     */
    public static function for(?User $user): array
    {
        $canView = (bool) $user?->can('recruitment.requirements.view');
        $canCreate = (bool) $user?->can('recruitment.requirements.create');

        return [
            'view' => $canView,
            'create' => $canCreate,
            'update' => (bool) $user?->can('recruitment.requirements.update'),
            'submit' => (bool) $user?->can('recruitment.requirements.submit'),
            'approve' => (bool) $user?->can('recruitment.requirements.approve'),
            'close' => (bool) $user?->can('recruitment.requirements.close'),
            'cancel' => (bool) $user?->can('recruitment.requirements.cancel'),
            'reopen' => (bool) $user?->can('recruitment.requirements.reopen'),
            'download_attachments' => (bool) $user?->can('recruitment.requirements.attachments.download'),
            'repeat' => $canView && $canCreate,
            'view_audit' => (bool) $user?->can('audit.view'),
            'request_deadline_extension' => (bool) $user?->can('recruitment.requirements.request_deadline_extension'),
            'request_headcount_revision' => (bool) $user?->can('recruitment.requirements.request_headcount_revision'),
        ];
    }
}
