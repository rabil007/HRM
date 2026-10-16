<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Platform\PlatformAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class ImpersonationService
{
    /**
     * Flag indicating an impersonation transition is occurring, to prevent
     * listeners like RecordUserLastLogin from updating last_login_at or password confirmation.
     */
    public static bool $isTransitioning = false;

    public function __construct(
        private ResolveCompanyAccess $resolveCompanyAccess,
    ) {}

    /**
     * Determine whether an initiating user can impersonate a target user in a company.
     */
    public function canImpersonate(?User $initiator, User $target, int $companyId): bool
    {
        if ($initiator === null || $companyId <= 0) {
            return false;
        }

        if ($initiator->id === $target->id) {
            return false;
        }

        if ($initiator->status !== 'active' || $initiator->deleted_at !== null || ! UserAccountStatus::allowsAuthentication($initiator)) {
            return false;
        }

        if ($target->status !== 'active' || $target->deleted_at !== null || ! UserAccountStatus::allowsAuthentication($target)) {
            return false;
        }

        if ($this->isImpersonating()) {
            return false;
        }

        if (! $this->resolveCompanyAccess->canAccess($initiator, $companyId)) {
            return false;
        }

        if (! $this->resolveCompanyAccess->canAccess($target, $companyId)) {
            return false;
        }

        if (! $this->hasImpersonatePermissionInCompany($initiator, $companyId)) {
            return false;
        }

        if ($this->isProtectedAccount($target, $companyId, $initiator)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a user holds the users.impersonate permission specifically
     * within a company's Spatie permission-team context.
     */
    public function hasImpersonatePermissionInCompany(User $user, int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        if (! $this->resolveCompanyAccess->canAccess($user, $companyId)) {
            return false;
        }

        if ($user->status !== 'active' || $user->deleted_at !== null || ! UserAccountStatus::allowsAuthentication($user)) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($companyId);
            $registrar->forgetCachedPermissions();
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            return (bool) $user->can('users.impersonate');
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $registrar->forgetCachedPermissions();
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }
    }

    /**
     * Whether a target user is a protected privileged account that cannot be impersonated.
     */
    public function isProtectedAccount(User $target, ?int $companyId = null, ?User $initiator = null): bool
    {
        if (PlatformAuthorization::canView($target) || ! empty($target->platform_access)) {
            return true;
        }

        if (UnrestrictedCompanyAccess::grants($target)) {
            return true;
        }

        // 1. Check if target user has Owner role in ANY company
        $hasOwnerRole = DB::table('spatie_model_has_roles')
            ->join('spatie_roles', 'spatie_roles.id', '=', 'spatie_model_has_roles.role_id')
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.model_id', $target->id)
            ->where('spatie_roles.name', 'Owner')
            ->exists();

        if ($hasOwnerRole) {
            return true;
        }

        // 2. Prevent privilege escalation: direct Spatie assignment of users.impersonate in ANY company
        $hasDirectImpersonate = DB::table('spatie_model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'spatie_model_has_permissions.permission_id')
            ->where('spatie_model_has_permissions.model_type', User::class)
            ->where('spatie_model_has_permissions.model_id', $target->id)
            ->where('permissions.name', 'users.impersonate')
            ->exists();

        if ($hasDirectImpersonate) {
            return true;
        }

        // 3. Prevent privilege escalation: role-derived users.impersonate in ANY company
        $hasRoleImpersonate = DB::table('spatie_role_has_permissions')
            ->join('spatie_model_has_roles', function ($join) use ($target): void {
                $join->on('spatie_model_has_roles.role_id', '=', 'spatie_role_has_permissions.role_id')
                    ->where('spatie_model_has_roles.model_type', '=', User::class)
                    ->where('spatie_model_has_roles.model_id', '=', $target->id);
            })
            ->join('permissions', 'permissions.id', '=', 'spatie_role_has_permissions.permission_id')
            ->where('permissions.name', 'users.impersonate')
            ->exists();

        if ($hasRoleImpersonate) {
            return true;
        }

        // 4. Close cross-company privilege escalation:
        // Inspect target-user administrative authority across all accessible companies.
        // Prevent an initiator authorized only in Company A from gaining administrative authority in Company B
        // through a shared target account where the initiator does not hold users.impersonate.
        if ($initiator !== null) {
            $administrativePermissions = [
                'users.impersonate',
                'roles.create',
                'roles.update',
                'roles.delete',
                'users.create',
                'users.update',
                'users.delete',
            ];

            $targetDirectAdminCompanies = DB::table('spatie_model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'spatie_model_has_permissions.permission_id')
                ->where('spatie_model_has_permissions.model_type', User::class)
                ->where('spatie_model_has_permissions.model_id', $target->id)
                ->whereIn('permissions.name', $administrativePermissions)
                ->pluck('spatie_model_has_permissions.company_id')
                ->all();

            $targetRoleAdminCompanies = DB::table('spatie_role_has_permissions')
                ->join('spatie_model_has_roles', function ($join) use ($target): void {
                    $join->on('spatie_model_has_roles.role_id', '=', 'spatie_role_has_permissions.role_id')
                        ->where('spatie_model_has_roles.model_type', '=', User::class)
                        ->where('spatie_model_has_roles.model_id', '=', $target->id);
                })
                ->join('permissions', 'permissions.id', '=', 'spatie_role_has_permissions.permission_id')
                ->whereIn('permissions.name', $administrativePermissions)
                ->pluck('spatie_model_has_roles.company_id')
                ->all();

            $targetAdminCompanies = array_unique(array_filter(array_merge($targetDirectAdminCompanies, $targetRoleAdminCompanies)));

            foreach ($targetAdminCompanies as $adminCompanyId) {
                if (! $this->canManageCompany($initiator, (int) $adminCompanyId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check whether an initiating user is authorized to manage/impersonate in a company.
     */
    private function canManageCompany(User $initiator, int $companyId): bool
    {
        if (! $this->resolveCompanyAccess->canAccess($initiator, $companyId)) {
            return false;
        }

        if (PlatformAuthorization::canView($initiator) || ! empty($initiator->platform_access)) {
            return true;
        }

        $hasDirect = DB::table('spatie_model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'spatie_model_has_permissions.permission_id')
            ->where('spatie_model_has_permissions.model_type', User::class)
            ->where('spatie_model_has_permissions.model_id', $initiator->id)
            ->where('spatie_model_has_permissions.company_id', $companyId)
            ->where('permissions.name', 'users.impersonate')
            ->exists();

        if ($hasDirect) {
            return true;
        }

        return DB::table('spatie_role_has_permissions')
            ->join('spatie_model_has_roles', function ($join) use ($initiator, $companyId): void {
                $join->on('spatie_model_has_roles.role_id', '=', 'spatie_role_has_permissions.role_id')
                    ->where('spatie_model_has_roles.model_type', '=', User::class)
                    ->where('spatie_model_has_roles.model_id', '=', $initiator->id)
                    ->where('spatie_model_has_roles.company_id', '=', $companyId);
            })
            ->join('permissions', 'permissions.id', '=', 'spatie_role_has_permissions.permission_id')
            ->where('permissions.name', 'users.impersonate')
            ->exists();
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

        if ($companyId <= 0) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Invalid company context.', $companyId);
            abort(403, 'A valid company context is required.');
        }

        if ($this->isImpersonating($request)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Nested impersonation attempt.', $companyId);
            abort(403, 'Nested impersonation is not allowed.');
        }

        if ($initiator->status !== 'active' || $initiator->deleted_at !== null || ! UserAccountStatus::allowsAuthentication($initiator)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Initiator account is not active or eligible.', $companyId);
            abort(403, 'Your account is not active or eligible to impersonate users.');
        }

        if (! $this->resolveCompanyAccess->canAccess($initiator, $companyId)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Initiator lacks active membership in the selected company.', $companyId);
            abort(403, 'You do not have access to the selected company.');
        }

        if (! $this->hasImpersonatePermissionInCompany($initiator, $companyId)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Initiator lacks users.impersonate permission in the selected company.', $companyId);
            abort(403, 'You do not have permission to impersonate users in this company.');
        }

        if ($target->id === $initiator->id) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Cannot impersonate self.', $companyId);
            abort(403, 'Cannot impersonate yourself.');
        }

        if ($target->status !== 'active' || $target->deleted_at !== null || ! UserAccountStatus::allowsAuthentication($target)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Target account is not active or eligible.', $companyId);
            abort(422, 'Target account is not active or eligible for impersonation.');
        }

        if (! $this->resolveCompanyAccess->canAccess($target, $companyId)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Target user does not belong to active company.', $companyId);
            abort(403, 'Target user does not belong to the active company.');
        }

        if ($this->isProtectedAccount($target, $companyId, $initiator)) {
            $this->logDeniedAttempt($request, $initiator, $target, 'Target account is a protected privileged account.', $companyId);
            abort(403, 'Protected privileged accounts cannot be impersonated.');
        }

        PrivilegedTwoFactorPolicy::assertSatisfied($initiator);
        $this->assertRecentAuthentication($request, $initiator);

        $correlationId = (string) Str::uuid();
        $lifetimeMinutes = (int) config('auth.impersonation.lifetime_minutes', 120);
        $expiresAt = now()->addMinutes($lifetimeMinutes)->timestamp;

        self::$isTransitioning = true;
        try {
            $request->session()->put('impersonation', [
                'original_user_id' => $initiator->id,
                'original_company_id' => $companyId,
                'impersonated_company_id' => $companyId,
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

            // Ensure the impersonated session does not inherit initiator's password confirmation
            $request->session()->forget('auth.password_confirmed_at');

            $request->session()->put('current_company_id', $companyId);
            $request->attributes->set('current_company_id', $companyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $target->unsetRelation('roles');
            $target->unsetRelation('permissions');
        } finally {
            self::$isTransitioning = false;
        }

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
                'timestamp' => now()->timestamp,
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
        if (! $this->hasImpersonatePermissionInCompany($originalUser, $originalCompanyId)) {
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
                'timestamp' => now()->timestamp,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log('Stopped impersonating');

        self::$isTransitioning = true;
        try {
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

            if ($companyToRestore === null) {
                $this->failClosed($request, 'Original administrator has no accessible companies to restore.');
            }

            $request->session()->put('current_company_id', $companyToRestore);
            $request->attributes->set('current_company_id', $companyToRestore);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyToRestore);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $originalUser->unsetRelation('roles');
            $originalUser->unsetRelation('permissions');
        } finally {
            self::$isTransitioning = false;
        }

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
                'timestamp' => now()->timestamp,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log('Impersonation session expired');

        if ($originalUser && UserAccountStatus::allowsAuthentication($originalUser)) {
            self::$isTransitioning = true;
            try {
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

                if ($companyToRestore === null) {
                    $this->failClosed($request, 'Original administrator has no accessible companies to restore.');
                }

                $request->session()->put('current_company_id', $companyToRestore);
                $request->attributes->set('current_company_id', $companyToRestore);
                app(PermissionRegistrar::class)->setPermissionsTeamId($companyToRestore);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $originalUser->unsetRelation('roles');
                $originalUser->unsetRelation('permissions');
            } finally {
                self::$isTransitioning = false;
            }

            return $originalUser;
        }

        $this->failClosed($request, 'Original administrator is no longer eligible after session expiry.');
    }

    /**
     * Log denied attempt.
     */
    public function logDeniedAttempt(Request $request, ?User $initiator, ?User $target, string $reason, ?int $companyId = null, ?string $correlationId = null): void
    {
        $resolvedCompanyId = $companyId ?? ($request->attributes->get('current_company_id') ?? $request->session()->get('current_company_id'));

        activity('impersonation')
            ->causedBy($initiator)
            ->event('denied')
            ->withProperties([
                'initiator_id' => $initiator?->id,
                'target_id' => $target?->id,
                'target_name' => $target?->name,
                'reason' => $reason,
                'company_id' => $resolvedCompanyId,
                'correlation_id' => $correlationId,
                'timestamp' => now()->timestamp,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log("Denied impersonation attempt: {$reason}");
    }

    /**
     * Check whether initiating user has recent authentication in the current session.
     */
    public function hasRecentAuthentication(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $timeout = (int) config('auth.password_timeout', 10800);

        return is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < $timeout;
    }

    /**
     * Assert initiating user has recent authentication in the current session.
     */
    public function assertRecentAuthentication(Request $request, User $user, ?int $companyId = null): void
    {
        if ($this->hasRecentAuthentication($request)) {
            return;
        }

        $this->logDeniedAttempt($request, $user, null, 'Initiator requires recent authentication or password confirmation.', $companyId);
        abort(423, 'Recent authentication or password confirmation required before impersonating a user.');
    }

    /**
     * Fail closed: log security incident, invalidate session, log out, abort 403.
     */
    public function failClosed(Request $request, string $reason): never
    {
        $sessionData = $request->hasSession() ? $request->session()->get('impersonation') : null;
        $originalUserId = $sessionData['original_user_id'] ?? null;
        $targetUserId = $sessionData['target_user_id'] ?? null;
        $originalCompanyId = $sessionData['original_company_id'] ?? null;
        $correlationId = $sessionData['correlation_id'] ?? null;

        $originalUser = $originalUserId ? User::find($originalUserId) : null;
        $currentUser = $request->user();

        activity('impersonation')
            ->causedBy($originalUser ?: $currentUser)
            ->event('terminated')
            ->withProperties([
                'reason' => $reason,
                'initiator_id' => $originalUserId,
                'target_id' => $targetUserId,
                'company_id' => $originalCompanyId,
                'correlation_id' => $correlationId,
                'timestamp' => now()->timestamp,
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
