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

        // 3. Validate company context integrity
        $chosenCompanyId = (int) ($sessionData['impersonated_company_id'] ?? $sessionData['original_company_id'] ?? 0);
        $originalCompanyId = (int) ($sessionData['original_company_id'] ?? 0);

        if ($chosenCompanyId <= 0 || $originalCompanyId <= 0) {
            $this->impersonationService->failClosed($request, 'Impersonated company context is missing or invalid.');
        }

        // 4. Validate original administrator company access
        if (! $this->companyAccess->canAccess($originalUser, $originalCompanyId)) {
            activity('impersonation')
                ->causedBy($originalUser)
                ->event('initiator_access_revoked')
                ->withProperties([
                    'initiator_id' => $originalUserId,
                    'initiator_name' => $originalUser->name,
                    'initiator_email' => $originalUser->email,
                    'target_id' => (int) ($sessionData['target_user_id'] ?? 0),
                    'company_id' => $originalCompanyId,
                    'correlation_id' => $sessionData['correlation_id'] ?? null,
                    'timestamp' => now()->timestamp,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('Terminated impersonation because original administrator lost company access');

            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'Your access to this company was revoked. Returned to your original account.');
        }

        // 5. Validate original administrator's impersonation permission
        if (! $this->impersonationService->hasImpersonatePermissionInCompany($originalUser, $originalCompanyId)) {
            activity('impersonation')
                ->causedBy($originalUser)
                ->event('initiator_permission_revoked')
                ->withProperties([
                    'initiator_id' => $originalUserId,
                    'initiator_name' => $originalUser->name,
                    'initiator_email' => $originalUser->email,
                    'target_id' => (int) ($sessionData['target_user_id'] ?? 0),
                    'company_id' => $originalCompanyId,
                    'correlation_id' => $sessionData['correlation_id'] ?? null,
                    'timestamp' => now()->timestamp,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('Terminated impersonation because original administrator lost impersonation permission');

            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'Your impersonation permission was revoked. Returned to your original account.');
        }

        // 6. Validate effective target user
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

        // 7. Enforce target company access
        if (! $this->companyAccess->canAccess($targetUser, $chosenCompanyId)) {
            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'The impersonated user no longer has access to this company. Returned to your original account.');
        }

        // 8. Revalidate protected accounts & role elevation during active impersonation
        if ($this->impersonationService->isProtectedAccount($targetUser, $chosenCompanyId, $originalUser)) {
            activity('impersonation')
                ->causedBy($currentUser)
                ->event('target_privilege_elevation_detected')
                ->withProperties([
                    'initiator_id' => $originalUserId,
                    'initiator_name' => $originalUser->name,
                    'initiator_email' => $originalUser->email,
                    'target_id' => $targetUserId,
                    'company_id' => $chosenCompanyId,
                    'correlation_id' => $sessionData['correlation_id'] ?? null,
                    'timestamp' => now()->timestamp,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('Terminated impersonation due to target user receiving protected privileges');

            $this->impersonationService->expire($request);

            return redirect()->route('dashboard')->with('error', 'The impersonated user received protected privileges. Returned to your original account.');
        }

        // 9. Enforce strict company context: restore/pin the verified chosen company before downstream authorization
        $request->session()->put('current_company_id', $chosenCompanyId);
        $request->attributes->set('current_company_id', $chosenCompanyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($chosenCompanyId);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $targetUser->unsetRelation('roles');
        $targetUser->unsetRelation('permissions');

        // 10. Restrict sensitive operations during impersonation
        if ($this->isRestrictedAction($request)) {
            activity('impersonation')
                ->causedBy($currentUser)
                ->event('restricted_action_blocked')
                ->withProperties([
                    'initiator_id' => $originalUserId,
                    'target_id' => $targetUserId,
                    'company_id' => $chosenCompanyId,
                    'correlation_id' => $sessionData['correlation_id'] ?? null,
                    'timestamp' => now()->timestamp,
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
        if (in_array($routeName, [
            'organization.users.impersonate',
            'organization.impersonation.confirm',
            'organization.impersonation.complete',
            'organization.impersonation.cancel',
        ], true)) {
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
