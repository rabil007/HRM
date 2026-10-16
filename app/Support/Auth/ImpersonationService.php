<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Platform\PlatformAuthorization;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class ImpersonationService
{
    public function __construct(
        private ResolveCompanyAccess $resolveCompanyAccess,
    ) {}

    /**
     * Determine whether an initiating user can impersonate a target user in a company.
     */
    public function canImpersonate(?User $initiator, User $target, int $companyId): bool
    {
        if ($initiator === null) {
            return false;
        }

        if ($initiator->id === $target->id) {
            return false;
        }

        if ($target->status !== 'active' || $target->deleted_at !== null) {
            return false;
        }

        if (! UserAccountStatus::allowsAuthentication($target)) {
            return false;
        }

        if ($this->isImpersonating()) {
            return false;
        }

        if (! $initiator->can('users.impersonate')) {
            return false;
        }

        if (! $this->resolveCompanyAccess->canAccess($target, $companyId)) {
            return false;
        }

        if ($this->isProtectedAccount($target, $companyId)) {
            return false;
        }

        return true;
    }

    /**
     * Whether a target user is a protected privileged account that cannot be impersonated.
     */
    public function isProtectedAccount(User $target, int $companyId): bool
    {
        if (PlatformAuthorization::canView($target) || ! empty($target->platform_access)) {
            return true;
        }

        if (UnrestrictedCompanyAccess::grants($target)) {
            return true;
        }

        // Check if user has Owner role in the specified company
        $hasOwnerRole = DB::table('spatie_model_has_roles')
            ->join('spatie_roles', 'spatie_roles.id', '=', 'spatie_model_has_roles.role_id')
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.model_id', $target->id)
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->where('spatie_roles.name', 'Owner')
            ->exists();

        if ($hasOwnerRole) {
            return true;
        }

        // Prevent privilege escalation: accounts holding users.impersonate are protected
        $hasImpersonatePermission = DB::table('spatie_role_has_permissions')
            ->join('spatie_model_has_roles', function ($join) use ($target, $companyId): void {
                $join->on('spatie_model_has_roles.role_id', '=', 'spatie_role_has_permissions.role_id')
                    ->where('spatie_model_has_roles.model_type', '=', User::class)
                    ->where('spatie_model_has_roles.model_id', '=', $target->id)
                    ->where('spatie_model_has_roles.company_id', '=', $companyId);
            })
            ->join('permissions', 'permissions.id', '=', 'spatie_role_has_permissions.permission_id')
            ->where('permissions.name', 'users.impersonate')
            ->exists();

        return $hasImpersonatePermission;
    }

    /**
     * Check if the current session has an active impersonation.
     */
    public function isImpersonating(?Request $request = null): bool
    {
        $req = $request ?: (app()->bound('request') ? request() : null);

        return $req !== null
            && $req->hasSession()
            && $req->session()->has('impersonation.original_user_id');
    }

    /**
     * Get the original initiating user model if impersonating.
     */
    public function getImpersonator(?Request $request = null): ?User
    {
        if (! $this->isImpersonating($request)) {
            return null;
        }

        $id = ($request ?: request())->session()->get('impersonation.original_user_id');

        return $id ? User::find($id) : null;
    }

    /**
     * Get correlation ID of the current impersonation session.
     */
    public function getCorrelationId(?Request $request = null): ?string
    {
        if (! $this->isImpersonating($request)) {
            return null;
        }

        return ($request ?: request())->session()->get('impersonation.correlation_id');
    }

    /**
     * Get shared impersonation data for Inertia frontend.
     *
     * @return array{
     *     is_impersonating: bool,
     *     original_user: array{id: int, name: string, email: string},
     *     target_user: array{id: int, name: string, email: string, role_name: string|null},
     *     time_remaining_seconds: int
     * }|null
     */
    public function getSharedImpersonationData(?Request $request = null): ?array
    {
        if (! $this->isImpersonating($request)) {
            return null;
        }

        $req = $request ?: request();
        $data = $req->session()->get('impersonation');

        $originalUserId = $data['original_user_id'] ?? null;
        $originalUser = $originalUserId ? User::find($originalUserId) : null;
        $targetUser = $req->user();

        if (! $originalUser || ! $targetUser) {
            return null;
        }

        $companyId = (int) ($req->attributes->get('current_company_id') ?? $req->session()->get('current_company_id'));
        $roleName = DB::table('spatie_model_has_roles')
            ->join('spatie_roles', 'spatie_roles.id', '=', 'spatie_model_has_roles.role_id')
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.model_id', $targetUser->id)
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->value('spatie_roles.name');

        return [
            'is_impersonating' => true,
            'original_user' => [
                'id' => $originalUser->id,
                'name' => $originalUser->name,
                'email' => $originalUser->email,
            ],
            'target_user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role_name' => $roleName,
            ],
            'time_remaining_seconds' => max(0, ($data['expires_at'] ?? 0) - now()->timestamp),
        ];
    }

    /**
     * Start impersonating a target user.
     */
    public function start(Request $request, User $target, int $companyId): void
    {
        $initiator = $request->user();

        abort_unless($initiator instanceof User, 401);

        if ($this->isImpersonating($request)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Nested impersonation attempt.');
            abort(403, 'Nested impersonation is not allowed.');
        }

        if (! $initiator->can('users.impersonate')) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Initiator lacks users.impersonate permission.');
            abort(403, 'You do not have permission to impersonate users.');
        }

        PrivilegedTwoFactorPolicy::assertSatisfied($initiator);
        $this->assertRecentAuthentication($request, $initiator);

        if ($target->id === $initiator->id) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Cannot impersonate self.');
            abort(403, 'Cannot impersonate yourself.');
        }

        if ($target->status !== 'active' || $target->deleted_at !== null || ! UserAccountStatus::allowsAuthentication($target)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Target account is not active or eligible.');
            abort(422, 'Target account is not active or eligible for impersonation.');
        }

        if (! $this->resolveCompanyAccess->canAccess($target, $companyId)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Target user does not belong to active company.');
            abort(403, 'Target user does not belong to the active company.');
        }

        if ($this->isProtectedAccount($target, $companyId)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Target account is a protected privileged account.');
            abort(403, 'Protected privileged accounts cannot be impersonated.');
        }

        $correlationId = (string) Str::uuid();
        $lifetimeMinutes = (int) config('auth.impersonation.lifetime_minutes', 120);
        $expiresAt = now()->addMinutes($lifetimeMinutes)->timestamp;

        $request->session()->put('impersonation', [
            'original_user_id' => $initiator->id,
            'original_company_id' => $companyId,
            'target_user_id' => $target->id,
            'correlation_id' => $correlationId,
            'started_at' => now()->timestamp,
            'expires_at' => $expiresAt,
        ]);

        $request->session()->regenerate();
        Auth::guard('web')->login($target);
        $request->session()->put([
            'password_hash_'.Auth::getDefaultDriver() => $target->getAuthPassword(),
        ]);

        $request->session()->put('current_company_id', $companyId);
        $request->attributes->set('current_company_id', $companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $target->unsetRelation('roles');
        $target->unsetRelation('permissions');

        activity('impersonation')
            ->causedBy($initiator)
            ->performedOn($target)
            ->event('started')
            ->withProperties([
                'initiator_id' => $initiator->id,
                'initiator_name' => $initiator->name,
                'initiator_email' => $initiator->email,
                'target_id' => $target->id,
                'target_name' => $target->name,
                'target_email' => $target->email,
                'company_id' => $companyId,
                'correlation_id' => $correlationId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log("Started impersonating {$target->name}");
    }

    /**
     * Stop impersonation and restore original administrator identity.
     */
    public function stop(Request $request): User
    {
        abort_unless($this->isImpersonating($request), 403, 'No active impersonation session.');

        $data = $request->session()->get('impersonation');
        $originalUserId = (int) ($data['original_user_id'] ?? 0);
        $targetUserId = (int) ($data['target_user_id'] ?? 0);
        $originalCompanyId = (int) ($data['original_company_id'] ?? 0);
        $correlationId = $data['correlation_id'] ?? null;

        $originalUser = $originalUserId ? User::find($originalUserId) : null;

        if (! $originalUser || ! UserAccountStatus::allowsAuthentication($originalUser)) {
            $this->failClosed($request, 'Original administrator account is invalid or disabled.');
        }

        // Verify initiator still holds users.impersonate
        app(PermissionRegistrar::class)->setPermissionsTeamId($originalCompanyId);
        $originalUser->unsetRelation('roles');
        $originalUser->unsetRelation('permissions');

        if (! $originalUser->can('users.impersonate')) {
            $this->failClosed($request, 'Original administrator no longer has impersonation permission.');
        }

        $targetUser = $request->user();

        activity('impersonation')
            ->causedBy($originalUser)
            ->performedOn($targetUser ?: $originalUser)
            ->event('ended')
            ->withProperties([
                'initiator_id' => $originalUser->id,
                'initiator_name' => $originalUser->name,
                'initiator_email' => $originalUser->email,
                'target_id' => $targetUserId,
                'company_id' => $originalCompanyId,
                'correlation_id' => $correlationId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log('Stopped impersonating');

        $request->session()->forget('impersonation');
        $request->session()->regenerate();

        Auth::guard('web')->login($originalUser);
        $request->session()->put([
            'password_hash_'.Auth::getDefaultDriver() => $originalUser->getAuthPassword(),
        ]);

        $accessibleIds = $this->resolveCompanyAccess->accessibleCompanyIds($originalUser);
        $companyToRestore = ($originalCompanyId && in_array($originalCompanyId, $accessibleIds, true))
            ? $originalCompanyId
            : $this->resolveCompanyAccess->resolveFallbackCompanyId($originalUser, $accessibleIds);

        if ($companyToRestore !== null) {
            $request->session()->put('current_company_id', $companyToRestore);
            $request->attributes->set('current_company_id', $companyToRestore);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyToRestore);
        } else {
            $request->session()->forget('current_company_id');
            $request->attributes->remove('current_company_id');
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $originalUser->unsetRelation('roles');
        $originalUser->unsetRelation('permissions');

        return $originalUser;
    }

    /**
     * Terminate impersonation on session expiry.
     */
    public function expire(Request $request): ?User
    {
        if (! $this->isImpersonating($request)) {
            return null;
        }

        $data = $request->session()->get('impersonation');
        $originalUserId = (int) ($data['original_user_id'] ?? 0);
        $targetUserId = (int) ($data['target_user_id'] ?? 0);
        $originalCompanyId = (int) ($data['original_company_id'] ?? 0);
        $correlationId = $data['correlation_id'] ?? null;

        $originalUser = $originalUserId ? User::find($originalUserId) : null;
        $targetUser = $request->user();

        activity('impersonation')
            ->causedBy($originalUser ?: $targetUser)
            ->performedOn($targetUser ?: ($originalUser ?: null))
            ->event('expired')
            ->withProperties([
                'initiator_id' => $originalUserId,
                'target_id' => $targetUserId,
                'company_id' => $originalCompanyId,
                'correlation_id' => $correlationId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log('Impersonation session expired');

        if ($originalUser && UserAccountStatus::allowsAuthentication($originalUser)) {
            $request->session()->forget('impersonation');
            $request->session()->regenerate();
            Auth::guard('web')->login($originalUser);
            $request->session()->put([
                'password_hash_'.Auth::getDefaultDriver() => $originalUser->getAuthPassword(),
            ]);

            $accessibleIds = $this->resolveCompanyAccess->accessibleCompanyIds($originalUser);
            $companyToRestore = ($originalCompanyId && in_array($originalCompanyId, $accessibleIds, true))
                ? $originalCompanyId
                : $this->resolveCompanyAccess->resolveFallbackCompanyId($originalUser, $accessibleIds);

            if ($companyToRestore !== null) {
                $request->session()->put('current_company_id', $companyToRestore);
                $request->attributes->set('current_company_id', $companyToRestore);
                app(PermissionRegistrar::class)->setPermissionsTeamId($companyToRestore);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $originalUser->unsetRelation('roles');
            $originalUser->unsetRelation('permissions');

            return $originalUser;
        }

        $this->failClosed($request, 'Original administrator is no longer eligible after session expiry.');
    }

    /**
     * Log denied attempt.
     */
    public function logDeniedAttempt(Request $request, ?User $initiator, ?User $target, string $reason): void
    {
        activity('impersonation')
            ->causedBy($initiator)
            ->event('denied')
            ->withProperties([
                'initiator_id' => $initiator?->id,
                'target_id' => $target?->id,
                'target_name' => $target?->name,
                'reason' => $reason,
                'company_id' => $request->attributes->get('current_company_id') ?? $request->session()->get('current_company_id'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log("Denied impersonation attempt: {$reason}");
    }

    /**
     * Assert initiating user has recent authentication or step-up verification.
     */
    public function assertRecentAuthentication(Request $request, User $user): void
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $timeout = (int) config('auth.password_timeout', 10800);

        if (is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < $timeout) {
            return;
        }

        $lastLoginAt = $user->last_login_at;
        if ($lastLoginAt instanceof CarbonInterface && (now()->timestamp - $lastLoginAt->getTimestamp()) < $timeout) {
            return;
        }

        $this->logDeniedAttempt($request, $user, null, 'Initiator requires recent authentication or password confirmation.');
        abort(423, 'Recent authentication or password confirmation required before impersonating a user.');
    }

    /**
     * Fail closed: log security incident, invalidate session, log out, abort 403.
     */
    public function failClosed(Request $request, string $reason): never
    {
        activity('impersonation')
            ->event('terminated')
            ->withProperties([
                'reason' => $reason,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log("Impersonation security termination: {$reason}");

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, "Impersonation security validation failed: {$reason}");
    }
}
