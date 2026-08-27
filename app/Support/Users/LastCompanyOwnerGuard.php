<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class LastCompanyOwnerGuard
{
    /**
     * Check if it's safe to perform an action that might remove or deactivate this user's Owner role.
     * Returns true if safe, false if this would remove the last active Owner.
     */
    public static function check(User $user, int $companyId): bool
    {
        $ownerRole = Role::where('company_id', $companyId)->where('name', 'Owner')->first();

        if (! $ownerRole) {
            return true; // No Owner role defined, so it's safe
        }

        $isOwner = DB::table('spatie_model_has_roles')
            ->where('role_id', $ownerRole->id)
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('company_id', $companyId)
            ->exists();

        if (! $isOwner) {
            return true; // User is not an owner, safe to modify
        }

        $totalActiveOwners = DB::table('spatie_model_has_roles')
            ->join('users', 'users.id', '=', 'spatie_model_has_roles.model_id')
            ->where('spatie_model_has_roles.role_id', $ownerRole->id)
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->where('users.status', 'active')
            ->lockForUpdate()
            ->count();

        // If there are multiple active owners, it's safe to remove one
        if ($totalActiveOwners > 1) {
            return true;
        }

        // If this user is active and there's only 1 active owner (them), it's unsafe.
        if ($user->status === 'active' && $totalActiveOwners <= 1) {
            return false;
        }

        return true;
    }
}
