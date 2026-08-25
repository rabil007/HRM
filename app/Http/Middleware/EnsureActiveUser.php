<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\UserAccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Guest-accessible route names that must keep working after an inactive
     * session is cleared (signed links, webhooks, login/reset, 2FA challenge).
     *
     * @var list<string>
     */
    private const GUEST_ACCESSIBLE_ROUTES = [
        'login',
        'login.store',
        'logout',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'two-factor.login',
        'two-factor.login.store',
        'home',
        'service-worker',
        'organization.documents.share',
        'public.*',
        'whatsapp.webhook',
        'webhooks.*',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->rejectPendingTwoFactorChallenge($request)) {
            return redirect()->route('login');
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (UserAccountStatus::allowsAuthentication($user)) {
            return $next($request);
        }

        $this->logOut($request);

        if ($this->isGuestAccessible($request)) {
            return $next($request);
        }

        return redirect()->route('login');
    }

    private function rejectPendingTwoFactorChallenge(Request $request): bool
    {
        if (! $request->hasSession() || ! $request->session()->has('login.id')) {
            return false;
        }

        $pending = User::query()->find($request->session()->get('login.id'));

        if (UserAccountStatus::allowsAuthentication($pending instanceof User ? $pending : null)) {
            return false;
        }

        $request->session()->forget(['login.id', 'login.remember']);

        return $request->routeIs('two-factor.login', 'two-factor.login.store');
    }

    private function logOut(Request $request): void
    {
        Auth::guard('web')->logout();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function isGuestAccessible(Request $request): bool
    {
        return $request->routeIs(self::GUEST_ACCESSIBLE_ROUTES);
    }
}
