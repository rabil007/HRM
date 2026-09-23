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
     *     close: bool,
     *     cancel: bool,
     *     reopen: bool,
     *     download_attachments: bool,
     *     repeat: bool,
     *     view_audit: bool,
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
            'close' => (bool) $user?->can('recruitment.requirements.close'),
            'cancel' => (bool) $user?->can('recruitment.requirements.cancel'),
            'reopen' => (bool) $user?->can('recruitment.requirements.reopen'),
            'download_attachments' => (bool) $user?->can('recruitment.requirements.attachments.download'),
            'repeat' => $canView && $canCreate,
            'view_audit' => (bool) $user?->can('audit.view'),
        ];
    }
}
