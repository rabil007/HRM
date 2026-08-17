<?php

namespace App\Support\Platform;

use App\Enums\PlatformAccess;
use App\Models\User;

final class PlatformAuthorization
{
    public static function canView(?User $user): bool
    {
        return $user?->platform_access instanceof PlatformAccess;
    }

    public static function canManage(?User $user): bool
    {
        return $user?->platform_access === PlatformAccess::Manage;
    }

    public static function canViewDatabase(?User $user): bool
    {
        return self::canView($user) && self::databaseViewerEnabled();
    }

    public static function databaseViewerEnabled(): bool
    {
        $configured = config('platform.database_viewer.enabled');

        if ($configured === null || $configured === '') {
            return ! app()->environment('production');
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{view: bool, manage: bool, database: bool}
     */
    public static function sharedFlags(?User $user): array
    {
        return [
            'view' => self::canView($user),
            'manage' => self::canManage($user),
            'database' => self::canViewDatabase($user),
        ];
    }
}
