<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePrivilegedTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.privileged_two_factor.enforce', false)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null || ! $this->isPrivileged($user)) {
            return $next($request);
        }

        if ($this->isEnrollmentOrExitRequest($request)) {
            return $next($request);
        }

        if (method_exists($user, 'hasEnabledTwoFactorAuthentication')
            && $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if (! $user->can('settings.security.view')) {
            abort(403, 'Two-factor authentication is required for this privileged account. Contact an administrator to enable Security settings access.');
        }

        return redirect()
            ->route('security.edit')
            ->with('error', 'Two-factor authentication is required for privileged access. Enable it before continuing.');
    }

    private function isPrivileged(object $user): bool
    {
        foreach ((array) config('security.privileged_two_factor.permissions', []) as $permission) {
            if (is_string($permission) && $permission !== '' && $user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function isEnrollmentOrExitRequest(Request $request): bool
    {
        return $request->is(
            'settings/security',
            'user/two-factor-authentication',
            'user/confirmed-two-factor-authentication',
            'user/two-factor-recovery-codes',
            'logout',
        );
    }
}
