<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Central revocation when `users.status` leaves `active`.
 *
 * Remember tokens are always rotated so a remembered browser cannot silently
 * re-authenticate. Database sessions are deleted only when the configured
 * session driver is `database` and the `sessions` table has `user_id`.
 *
 * File, cookie, Redis, array, and similar session stores are not bulk-deleted
 * (Laravel does not index those stores by user). Request-time active-user
 * middleware still logs the user out on the next authenticated web request.
 * Do not guess PHP session file paths.
 */
final class RevokeDisabledUserAccess
{
    public function handle(User $user): void
    {
        if (UserAccountStatus::allowsAuthentication($user)) {
            return;
        }

        app(InvalidateUserSessions::class)->handle($user);
        $this->invalidateCurrentSessionIfTarget($user);
    }

    private function invalidateCurrentSessionIfTarget(User $user): void
    {
        $guard = Auth::guard('web');

        if (! $guard->hasUser() || (int) $guard->id() !== (int) $user->id) {
            return;
        }

        $guard->logout();

        $request = request();

        if (! $request instanceof Request || ! $request->hasSession()) {
            return;
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
