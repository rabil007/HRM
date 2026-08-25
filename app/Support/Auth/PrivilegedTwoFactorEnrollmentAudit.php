<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PrivilegedTwoFactorEnrollmentAudit
{
    /**
     * Active users who hold platform access or a catalogued privileged permission
     * in any company team, but do not have confirmed Fortify 2FA.
     *
     * Does not select or return two_factor_secret or recovery codes.
     *
     * @return list<array{
     *     id: int,
     *     email: string,
     *     platform_access: string|null,
     *     capabilities: list<string>,
     *     enrollment: 'missing'|'unconfirmed'
     * }>
     */
    public function unenrolledActiveUsers(): array
    {
        $users = DB::table((new User)->getTable())
            ->select(['id', 'email', 'platform_access'])
            ->selectRaw('CASE WHEN two_factor_secret IS NULL THEN 0 ELSE 1 END as has_two_factor_secret')
            ->whereNull('deleted_at')
            ->where('status', UserAccountStatus::ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('two_factor_confirmed_at')
                    ->orWhereNull('two_factor_secret');
            })
            ->where(function ($query): void {
                $query->whereNotNull('platform_access')
                    ->orWhereIn('id', $this->privilegedPermissionUserIds());
            })
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        $capabilities = $this->capabilitiesByUserId(
            $users->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        return $users->map(function (object $user) use ($capabilities): array {
            $userId = (int) $user->id;
            $userCapabilities = $capabilities[$userId] ?? [];
            $platformAccess = is_string($user->platform_access) && $user->platform_access !== ''
                ? $user->platform_access
                : null;

            if (is_string($platformAccess)) {
                array_unshift($userCapabilities, 'platform:'.$platformAccess);
            }

            return [
                'id' => $userId,
                'email' => (string) $user->email,
                'platform_access' => $platformAccess,
                'capabilities' => array_values(array_unique($userCapabilities)),
                'enrollment' => ((int) $user->has_two_factor_secret) === 1
                    ? 'unconfirmed'
                    : 'missing',
            ];
        })->all();
    }

    /**
     * Privileged or platform users who cannot authenticate (PR #44) and therefore
     * cannot complete privileged actions until they are active again.
     */
    public function inactivePrivilegedUserCount(): int
    {
        return DB::table((new User)->getTable())
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', UserAccountStatus::ACTIVE);
            })
            ->where(function ($query): void {
                $query->whereNotNull('platform_access')
                    ->orWhereIn('id', $this->privilegedPermissionUserIds());
            })
            ->count();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function capabilitiesByUserId(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $tables = config('permission.table_names');
        $teamKey = (string) config('permission.column_names.team_foreign_key');
        $modelType = (new User)->getMorphClass();
        $permissionNames = PrivilegedTwoFactorPolicy::PERMISSIONS;

        $viaRoles = DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', $modelType)
            ->whereIn('mhr.model_id', $userIds)
            ->whereIn('p.name', $permissionNames)
            ->select([
                'mhr.model_id as user_id',
                'p.name as permission',
                "mhr.{$teamKey} as company_id",
            ]);

        $viaDirect = DB::table($tables['model_has_permissions'].' as mhp')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', $modelType)
            ->whereIn('mhp.model_id', $userIds)
            ->whereIn('p.name', $permissionNames)
            ->select([
                'mhp.model_id as user_id',
                'p.name as permission',
                "mhp.{$teamKey} as company_id",
            ]);

        $grouped = [];

        foreach ($viaRoles->union($viaDirect)->get() as $row) {
            $label = (string) $row->permission;

            if ($row->company_id !== null && $row->company_id !== '') {
                $label .= '@'.$row->company_id;
            }

            $grouped[(int) $row->user_id][] = $label;
        }

        foreach ($grouped as $userId => $items) {
            $unique = array_values(array_unique($items));
            sort($unique);
            $grouped[$userId] = $unique;
        }

        return $grouped;
    }

    private function privilegedPermissionUserIds(): Builder
    {
        $tables = config('permission.table_names');
        $modelType = (new User)->getMorphClass();
        $permissionNames = PrivilegedTwoFactorPolicy::PERMISSIONS;

        $viaRoles = DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', $modelType)
            ->whereIn('p.name', $permissionNames)
            ->select('mhr.model_id');

        $viaDirect = DB::table($tables['model_has_permissions'].' as mhp')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', $modelType)
            ->whereIn('p.name', $permissionNames)
            ->select('mhp.model_id');

        return $viaRoles->union($viaDirect);
    }
}
