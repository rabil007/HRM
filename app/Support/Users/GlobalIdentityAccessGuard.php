<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class GlobalIdentityAccessGuard
{
    /**
     * Ensure the active company is the home company for this user.
     * This prevents administrators from modifying global user properties
     * (like password, global status, or deleting the user) if the user
     * belongs to a different company natively and is only a member here.
     *
     * @throws AuthorizationException
     */
    public static function check(User $user, int $currentCompanyId): void
    {
        if ($user->company_id !== $currentCompanyId) {
            throw new AuthorizationException('You cannot modify the global identity of a user whose home company is not this company. Manage their membership instead.');
        }
    }
}
