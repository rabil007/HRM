<?php

namespace App\Support\Auth;

use App\Models\User;
use Spatie\Permission\Models\Permission;

final class UnrestrictedCompanyAccess
{
    public const EMAIL = 'admin@example.com';

    public static function grants(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return UserEmailIdentity::normalize((string) $user->email) === self::EMAIL;
    }

    public static function allowsAbility(?User $user, string $ability): bool
    {
        if (! self::grants($user)) {
            return false;
        }

        return in_array($ability, self::permissionNames(), true);
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        /** @var list<string> */
        return once(fn (): array => Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all());
    }
}
