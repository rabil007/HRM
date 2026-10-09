<?php

namespace App\Support\AppRefresh;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight authorization revision tokens for frontend permission sync.
 *
 * - User revision bumps on membership / role assignment changes for that user.
 * - Company revision bumps when role permissions change (affects all assignees).
 *
 * Combined token is shared via Inertia and polled by the client. Backend
 * authorization remains authoritative on every protected request.
 */
final class AuthorizationRevision
{
    public static function current(User $user, ?int $companyId): string
    {
        $userRevision = (int) ($user->authorization_revision ?? 1);
        $companyRevision = 0;

        if ($companyId !== null && $companyId > 0) {
            $companyRevision = (int) (Company::query()
                ->whereKey($companyId)
                ->value('authorization_revision') ?? 1);
        }

        return "{$userRevision}.{$companyRevision}";
    }

    public static function bumpUser(User $user): void
    {
        User::query()->whereKey($user->id)->increment('authorization_revision');
        $user->refresh();

        Cache::forget(self::companiesCacheKey($user->id));
    }

    public static function bumpCompany(int $companyId): void
    {
        if ($companyId < 1) {
            return;
        }

        Company::query()->whereKey($companyId)->increment('authorization_revision');
    }

    public static function companiesCacheKey(int $userId): string
    {
        return "inertia:shared:{$userId}:companies";
    }

    public static function permissionsCacheKey(int $userId, int $companyId, string $revision): string
    {
        return "inertia:shared:{$userId}:company:{$companyId}:r{$revision}:permissions";
    }

    public static function rolesCacheKey(int $userId, int $companyId, string $revision): string
    {
        return "inertia:shared:{$userId}:company:{$companyId}:r{$revision}:roles";
    }
}
