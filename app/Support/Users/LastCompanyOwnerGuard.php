<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class LastCompanyOwnerGuard
{
    /**
     * Check if it's safe to perform an action that might remove, deactivate,
     * or detach this user's Owner role in the specified company.
     *
     * Returns true if safe, false if this would remove the company's last active Owner.
     */
    public static function check(User $user, int $companyId): bool
    {
        $ownerRole = Role::query()
            ->where('company_id', $companyId)
            ->where('name', 'Owner')
            ->first();

        if (! $ownerRole) {
            return true; // No Owner role defined for this tenant, safe
        }

        $activeOwnerUserIds = DB::table('spatie_model_has_roles')
            ->join('users', 'users.id', '=', 'spatie_model_has_roles.model_id')
            ->where('spatie_model_has_roles.role_id', $ownerRole->id)
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->where(function ($query) use ($companyId) {
                $query->whereExists(function ($inner) use ($companyId) {
                    $inner->select(DB::raw(1))
                        ->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $companyId)
                        ->where('company_user.status', 'active');
                })->orWhere(function ($inner) use ($companyId) {
                    $inner->where('users.company_id', $companyId)
                        ->whereNotExists(function ($pivot) use ($companyId) {
                            $pivot->select(DB::raw(1))
                                ->from('company_user')
                                ->whereColumn('company_user.user_id', 'users.id')
                                ->where('company_user.company_id', $companyId);
                        });
                });
            })
            ->lockForUpdate()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // If target user is not one of the active owners, this action does not reduce the active owner count
        if (! in_array((int) $user->id, $activeOwnerUserIds, true)) {
            return true;
        }

        // If there is only 1 active owner (or none), removing or deactivating this user leaves the company with 0 active owners
        return count(array_unique($activeOwnerUserIds)) > 1;
    }
}
