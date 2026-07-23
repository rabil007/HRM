<?php

namespace App\Support\Announcements;

use App\Models\User;

final class AnnouncementPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     publish: bool,
     *     cancel: bool,
     *     retry: bool,
     *     download_attachments: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('announcements.view') ?? false,
            'create' => $user?->can('announcements.create') ?? false,
            'update' => $user?->can('announcements.update') ?? false,
            'publish' => $user?->can('announcements.publish') ?? false,
            'cancel' => $user?->can('announcements.cancel') ?? false,
            'retry' => $user?->can('announcements.retry') ?? false,
            'download_attachments' => $user?->can('announcements.download_attachments') ?? false,
        ];
    }
}
