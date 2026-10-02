<?php

namespace App\Support\Recruitment;

use App\Models\Company;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Active company users eligible as additional notification CC recipients.
 *
 * Membership follows ResolveCompanyAccess: active company_user pivot, or legacy
 * users.company_id match only when no pivot row exists for that company.
 */
final class CompanyUserOptionsQuery
{
    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function forCompany(int $companyId): array
    {
        return self::baseQuery($companyId)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
            ])
            ->all();
    }

    public static function baseQuery(int $companyId): Builder
    {
        $companyIsActive = Company::query()
            ->whereKey($companyId)
            ->where('status', 'active')
            ->exists();

        if (! $companyIsActive) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereExists(function ($inner) use ($companyId): void {
                    $inner->select(DB::raw(1))
                        ->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $companyId)
                        ->where('company_user.status', 'active');
                })->orWhere(function (Builder $legacy) use ($companyId): void {
                    $legacy->where('users.company_id', $companyId)
                        ->whereNotExists(function ($inner) use ($companyId): void {
                            $inner->select(DB::raw(1))
                                ->from('company_user')
                                ->whereColumn('company_user.user_id', 'users.id')
                                ->where('company_user.company_id', $companyId);
                        });
                });
            });
    }

    public static function isActiveCompanyMember(int $userId, int $companyId): bool
    {
        $user = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id', 'company_id', 'status', 'deleted_at']);

        if ($user === null) {
            return false;
        }

        return app(ResolveCompanyAccess::class)->hasAccessibleMembership($user, $companyId);
    }
}
