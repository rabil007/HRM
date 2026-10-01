<?php

namespace App\Support\Recruitment;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Eligible assigned recruiters: active company members with recruitment.requirements.approve.
 */
final class RecruiterOptionsQuery
{
    public const APPROVE_PERMISSION = 'recruitment.requirements.approve';

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function forCompany(int $companyId): array
    {
        return self::eligibleApproverQuery($companyId)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
            ])
            ->all();
    }

    public static function eligibleApproverQuery(int $companyId): Builder
    {
        $permissionId = self::approvePermissionId();
        if ($permissionId === null) {
            return User::query()->whereRaw('1 = 0');
        }

        $teamKey = config('permission.column_names.team_foreign_key', 'company_id');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $modelHasRoles = config('permission.table_names.model_has_roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');

        return CompanyUserOptionsQuery::baseQuery($companyId)
            ->where(function (Builder $query) use ($companyId, $permissionId, $teamKey, $modelHasPermissions, $modelHasRoles, $roleHasPermissions): void {
                $query->whereExists(function ($inner) use ($companyId, $permissionId, $teamKey, $modelHasPermissions): void {
                    $inner->select(DB::raw(1))
                        ->from($modelHasPermissions)
                        ->whereColumn($modelHasPermissions.'.model_id', 'users.id')
                        ->where($modelHasPermissions.'.model_type', User::class)
                        ->where($modelHasPermissions.'.permission_id', $permissionId)
                        ->where($modelHasPermissions.'.'.$teamKey, $companyId);
                })->orWhereExists(function ($inner) use ($companyId, $permissionId, $teamKey, $modelHasRoles, $roleHasPermissions): void {
                    $inner->select(DB::raw(1))
                        ->from($modelHasRoles)
                        ->join($roleHasPermissions, $roleHasPermissions.'.role_id', '=', $modelHasRoles.'.role_id')
                        ->whereColumn($modelHasRoles.'.model_id', 'users.id')
                        ->where($modelHasRoles.'.model_type', User::class)
                        ->where($modelHasRoles.'.'.$teamKey, $companyId)
                        ->where($roleHasPermissions.'.permission_id', $permissionId);
                });
            });
    }

    public static function isEligibleApprover(?int $userId, int $companyId): bool
    {
        if ($userId === null) {
            return false;
        }

        return self::eligibleApproverQuery($companyId)
            ->where('users.id', $userId)
            ->exists();
    }

    /**
     * @deprecated Use isEligibleApprover for assigned recruiters. Kept for nullable draft assigned_to checks.
     */
    public static function isValidForCompany(?int $userId, int $companyId): bool
    {
        if ($userId === null) {
            return true;
        }

        return self::isEligibleApprover($userId, $companyId);
    }

    /**
     * Validate assigned recruiter for create/update/submit flows.
     *
     * @throws ValidationException
     */
    public static function assertEligibleApprover(?int $userId, int $companyId, bool $required = false): void
    {
        if ($userId === null) {
            if ($required) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'An assigned recruiter with approval permission is required.',
                ]);
            }

            return;
        }

        if (! CompanyUserOptionsQuery::isActiveCompanyMember($userId, $companyId)) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected recruiter is inactive or does not belong to this company.',
            ]);
        }

        if (! self::isEligibleApprover($userId, $companyId)) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected recruiter must have recruitment approval permission.',
            ]);
        }
    }

    public static function baseQuery(int $companyId): Builder
    {
        return CompanyUserOptionsQuery::baseQuery($companyId);
    }

    private static function approvePermissionId(): ?int
    {
        $id = DB::table(config('permission.table_names.permissions'))
            ->where('guard_name', 'web')
            ->where('name', self::APPROVE_PERMISSION)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
