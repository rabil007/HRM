<?php

namespace App\Support\Recruitment;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class RecruiterOptionsQuery
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
        return User::query()
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->where(function (Builder $query) use ($companyId): void {
                $query->where('users.company_id', $companyId)
                    ->orWhereExists(function ($inner) use ($companyId): void {
                        $inner->select(DB::raw(1))
                            ->from('company_user')
                            ->whereColumn('company_user.user_id', 'users.id')
                            ->where('company_user.company_id', $companyId)
                            ->where('company_user.status', 'active');
                    });
            });
    }

    public static function isValidForCompany(?int $userId, int $companyId): bool
    {
        if ($userId === null) {
            return true;
        }

        return self::baseQuery($companyId)
            ->where('users.id', $userId)
            ->exists();
    }
}
