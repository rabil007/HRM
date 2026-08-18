<?php

namespace App\Support\Auth;

use App\Exceptions\PrivilegedTwoFactorRequiredException;
use App\Models\User;
use App\Support\Platform\PlatformAuthorization;
use Illuminate\Support\Facades\Log;

final class PrivilegedTwoFactorPolicy
{
    /**
     * Spatie permissions that unlock privileged (high-trust) operations.
     *
     * Platform access is user-level and is not listed here.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'roles.create',
        'roles.update',
        'roles.delete',
        'users.create',
        'users.update',
        'users.delete',
        'settings.application.update',
        'settings.integrations.whatsapp.update',
        'settings.integrations.hikvision.update',
        'hikvision.webhook.manage',
        'payroll.periods.approve',
        'payroll.periods.mark_paid',
        'payroll.wps.export',
        'crew_operations.assignments.void',
        'crew_operations.corrections.override',
        'attendance.leave-requests.delete_any',
    ];

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return self::PERMISSIONS;
    }

    public static function requiresPermission(string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true);
    }

    public static function isEnforced(): bool
    {
        return filter_var(config('security.privileged_two_factor.enforced'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function userHasEnrolledTwoFactor(?User $user): bool
    {
        return $user !== null && $user->hasEnabledTwoFactorAuthentication();
    }

    public static function userHoldsPrivilegedCapability(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (PlatformAuthorization::canView($user)) {
            return true;
        }

        return $user->getAllPermissions()
            ->pluck('name')
            ->intersect(self::PERMISSIONS)
            ->isNotEmpty();
    }

    public static function isSatisfied(?User $user): bool
    {
        return ! self::isEnforced() || self::userHasEnrolledTwoFactor($user);
    }

    /**
     * @return array{enabled: bool, required_for_privileged_actions: bool}
     */
    public static function sharedFlags(?User $user): array
    {
        return [
            'enabled' => self::userHasEnrolledTwoFactor($user),
            'required_for_privileged_actions' => self::isEnforced()
                && self::userHoldsPrivilegedCapability($user),
        ];
    }

    public static function assertSatisfied(User $user): void
    {
        if (self::isSatisfied($user)) {
            return;
        }

        Log::notice('Privileged action blocked: two-factor authentication required.', [
            'user_id' => $user->id,
            'route' => request()->route()?->getName(),
        ]);

        throw new PrivilegedTwoFactorRequiredException;
    }
}
