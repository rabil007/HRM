<?php

namespace App\Support\Auth;

use App\Models\User;

final class UserAccountStatus
{
    public const ACTIVE = 'active';

    /**
     * Only `users.status = active` may authenticate. Any other value, including
     * `inactive`, `suspended`, null, or unexpected strings, is denied.
     *
     * Platform/system users are still `User` rows and must also be active.
     * Company membership status is a separate concern and is not consulted here.
     */
    public static function allowsAuthentication(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->status === self::ACTIVE;
    }
}
