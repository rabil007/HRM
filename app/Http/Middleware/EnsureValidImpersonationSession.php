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
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'The impersonated user account is no longer active. Returned to your original account.');
        }

        // Enforce the chosen company boundary
        $chosenCompanyId = (int) ($sessionData['impersonated_company_id'] ?? $sessionData['original_company_id'] ?? 0);
        if ($chosenCompanyId && $currentCompanyId && $currentCompanyId !== $chosenCompanyId) {
            abort(403, 'Operating outside the chosen impersonation company boundary is not allowed.');
        }

        if ($chosenCompanyId && ! $this->companyAccess->canAccess($targetUser, $chosenCompanyId)) {
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'The impersonated user no longer has access to this company. Returned to your original account.');
        }

        // 5. Restrict sensitive operations during impersonation
        if ($this->isRestrictedAction($request)) {
            activity('impersonation')
                ->causedBy($currentUser)
                ->event('restricted_action_blocked')
                ->withProperties([
                    'initiator_id' => $originalUserId,
                    'target_id' => $targetUserId,
                    'company_id' => $currentCompanyId,
                    'correlation_id' => $sessionData['correlation_id'] ?? null,
                    'route' => (string) $request->route()?->getName(),
                    'path' => trim($request->path(), '/'),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('Blocked restricted sensitive action during impersonation');

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

        // Block password updates and confirmations
        if (str_contains($routeName, 'password.update')
            || in_array($routeName, ['user-password.update', 'password.confirm.store', 'password.update'], true)
            || str_contains($path, 'password')
            || $path === 'settings/security/password'
            || $path === 'settings/password'
            || $path === 'user/confirm-password') {
            return true;
        }

        // Block 2FA modifications
        if (str_starts_with($path, 'user/two-factor') || str_starts_with($routeName, 'two-factor.')) {
            return true;
        }

        // Block user identity mutations & administrative management
        if (in_array($routeName, [
            'organization.users.store',
            'organization.users.update',
            'organization.users.status',
            'organization.users.destroy',
            'organization.employees.user.store',
        ], true)) {
            return true;
        }

        // Block user company memberships management
        if (str_starts_with($routeName, 'organization.users.memberships.')) {
            return true;
        }

        // Block user security actions (password resets, revoking sessions)
        if (str_starts_with($routeName, 'organization.users.security.')) {
            return true;
        }

        // Block user invitations
        if (str_starts_with($routeName, 'organization.user-invitations.')) {
            return true;
        }

        // Block role & permission administration
        if (str_starts_with($routeName, 'organization.roles.')
            || in_array($routeName, ['organization.roles.store', 'organization.roles.update', 'organization.roles.destroy', 'organization.roles.duplicate'], true)) {
            return true;
        }

        // Block nested impersonation
        if ($routeName === 'organization.users.impersonate') {
            return true;
        }

        // Block financial release operations (approvals, mark-paid, WPS bank file export)
        if (in_array($routeName, [
            'payroll.approve',
            'payroll.mark-paid',
            'payroll.wps.export',
            'payroll.cancel',
            'payroll.revert-to-draft',
            'payroll.revert-to-processing',
            'payroll.revert-to-approved',
        ], true)) {
            return true;
        }

        return false;
    }
}
