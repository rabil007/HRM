<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * Marks the current session as recently password-verified for Fortify's
 * auth.password_confirmed_at semantics (password confirmation timeout).
 *
 * Only call this from trusted interactive authentication completion points
 * (successful Fortify login / completed 2FA challenge / password confirmation).
 * Do not call from Remember Me cookie restoration or impersonation transitions.
 */
final class RecentAuthentication
{
    public static function mark(?Request $request = null): void
    {
        $request ??= request();

        if (! $request->hasSession() || ImpersonationService::$isTransitioning) {
            return;
        }

        $request->session()->put('auth.password_confirmed_at', now()->timestamp);
    }
}
