<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\ImpersonationService;
use App\Support\Auth\UserAccountStatus;
use App\Support\Companies\ResolveCompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidImpersonationSession
{
    public function __construct(
        private ImpersonationService $impersonationService,
        private ResolveCompanyAccess $companyAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonationService->isImpersonating($request)) {
            return $next($request);
        }

        $sessionData = $request->session()->get('impersonation');
        $expiresAt = (int) ($sessionData['expires_at'] ?? 0);

        // 1. Check expiration
        if (now()->timestamp > $expiresAt) {
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('info', 'Your impersonation session has expired. You have been returned to your original account.');
        }

        // 2. Validate original administrator
        $originalUserId = $sessionData['original_user_id'] ?? null;
        $originalUser = $originalUserId ? User::find($originalUserId) : null;

        if (! $originalUser || ! UserAccountStatus::allowsAuthentication($originalUser)) {
            $this->impersonationService->failClosed($request, 'Original administrator account is no longer active.');
        }

        // 3. Validate original administrator's impersonation permission
        $originalCompanyId = (int) ($sessionData['original_company_id'] ?? 0);
        app(PermissionRegistrar::class)->setPermissionsTeamId($originalCompanyId);
        $originalUser->unsetRelation('roles');
        $originalUser->unsetRelation('permissions');

        if (! $originalUser->can('users.impersonate')) {
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'Your impersonation permission was revoked. Returned to your original account.');
        }

        // Re-set Spatie team to current company
        $currentCompanyId = (int) ($request->attributes->get('current_company_id') ?? $request->session()->get('current_company_id'));
        if ($currentCompanyId) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($currentCompanyId);
        }

        // 4. Validate effective target user
        $targetUserId = (int) ($sessionData['target_user_id'] ?? 0);
        $targetUser = $targetUserId ? User::find($targetUserId) : null;
        $currentUser = $request->user();

        if ($currentUser && $currentUser->id !== $targetUserId) {
            $this->impersonationService->failClosed($request, 'Target user mismatch in impersonated session.');
        }

        if (! $targetUser || ! UserAccountStatus::allowsAuthentication($targetUser)) {
            dump('TARGET USER IS SUSPENDED - CALLING EXPIRE');
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'The impersonated user account is no longer active. Returned to your original account.');
        }

        if ($currentCompanyId && ! $this->companyAccess->canAccess($targetUser, $currentCompanyId)) {
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'The impersonated user no longer has access to this company. Returned to your original account.');
        }

        // 5. Restrict sensitive operations during impersonation
        if ($this->isRestrictedAction($request)) {
            abort(403, 'This sensitive operation cannot be performed while impersonating another user.');
        }

        return $next($request);
    }

    private function isRestrictedAction(Request $request): bool
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return false;
        }

        $routeName = (string) $request->route()?->getName();
        $path = trim($request->path(), '/');

        // Allow stopping impersonation
        if ($routeName === 'organization.impersonation.stop') {
            return false;
        }

        // Block password updates
        if (str_contains($routeName, 'password.update') || $path === 'settings/security/password' || $path === 'password') {
            return true;
        }

        // Block 2FA modifications
        if (str_starts_with($path, 'user/two-factor') || str_starts_with($routeName, 'two-factor.')) {
            return true;
        }

        // Block role & permission administration
        if (in_array($routeName, ['organization.roles.store', 'organization.roles.update', 'organization.roles.destroy', 'organization.roles.duplicate'], true)) {
            return true;
        }

        // Block irreversible financial release: payroll approval & mark-paid
        if (in_array($routeName, ['payroll.approve', 'payroll.mark-paid'], true)) {
            return true;
        }

        // Block nested impersonation
        if ($routeName === 'organization.users.impersonate') {
            return true;
        }

        // Block user deletion or status mutation
        if (in_array($routeName, ['organization.users.status', 'organization.users.destroy'], true)) {
            return true;
        }

        return false;
    }
}
