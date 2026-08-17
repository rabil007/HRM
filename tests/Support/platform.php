<?php

use App\Enums\PlatformAccess;
use App\Models\User;

function grantPlatformAccess(User $user, string $level = 'view'): User
{
    $access = match ($level) {
        'view' => PlatformAccess::View,
        'manage' => PlatformAccess::Manage,
        default => throw new InvalidArgumentException("Invalid platform access level [{$level}]."),
    };

    $user->forceFill(['platform_access' => $access])->save();

    return $user->refresh();
}
