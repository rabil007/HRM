<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserDirectoryQuery
{
    /**
     * Get the users directory paginated list for the specified company.
     * Includes users whose home company is this company, OR who have a company_user membership.
     *
     * @param  string  $status  Account status filter
     * @param  string  $presence  Filter: 'online', 'recent', 'offline', 'never'
     */
    public function paginateForCompany(
        int $companyId,
        int $perPage,
        string $search = '',
        string $status = '',
        string $roleId = '',
        string $presence = ''
    ): LengthAwarePaginator {
        $now = time();
        $onlineThreshold = $now - (5 * 60);
        $recentThreshold = $now - (30 * 60);

        $latestSessionQuery = DB::table('sessions')
            ->select('user_id', DB::raw('MAX(last_activity) as latest_activity'))
            ->whereNotNull('user_id')
            ->groupBy('user_id');

        $query = User::query()
            ->select('users.*', 's.latest_activity')
            ->leftJoinSub($latestSessionQuery, 's', 's.user_id', '=', 'users.id')
            ->where(function ($q) use ($companyId) {
                $q->where('users.company_id', $companyId)
                    ->orWhereExists(function ($inner) use ($companyId) {
                        $inner->select(DB::raw(1))
                            ->from('company_user')
                            ->whereColumn('company_user.user_id', 'users.id')
                            ->where('company_user.company_id', $companyId);
                    });
            });

        if ($status !== '') {
            $query->where('users.status', $status);
        }

        if ($roleId !== '') {
            $query->whereHas('roles', function ($inner) use ($roleId, $companyId) {
                // Spatie roles are company-scoped
                $table = config('permission.table_names.roles');
                $inner->where($table.'.id', $roleId)->where($table.'.company_id', $companyId);
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if ($presence === 'online') {
            $query->where('s.latest_activity', '>=', $onlineThreshold);
        } elseif ($presence === 'recent') {
            $query->where('s.latest_activity', '<', $onlineThreshold)
                ->where('s.latest_activity', '>=', $recentThreshold);
        } elseif ($presence === 'offline') {
            $query->where(function ($q) use ($recentThreshold) {
                $q->where('s.latest_activity', '<', $recentThreshold)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('s.latest_activity')
                            ->whereNotNull('users.last_login_at');
                    });
            });
        } elseif ($presence === 'never') {
            $query->whereNull('users.last_login_at')
                ->whereNull('s.latest_activity');
        }

        return $query->latest('users.id')->paginate($perPage)->withQueryString();
    }

    /**
     * Get summary statistics for the users directory.
     *
     * @return array{total: int, online: int, never: int}
     */
    public function summaryForCompany(int $companyId): array
    {
        $now = time();
        $onlineThreshold = $now - (5 * 60);

        $latestSessionQuery = DB::table('sessions')
            ->select('user_id', DB::raw('MAX(last_activity) as latest_activity'))
            ->whereNotNull('user_id')
            ->groupBy('user_id');

        $query = User::query()
            ->selectRaw('
                COUNT(users.id) as total,
                SUM(CASE WHEN s.latest_activity >= ? THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN users.last_login_at IS NULL AND s.latest_activity IS NULL THEN 1 ELSE 0 END) as never
            ', [$onlineThreshold])
            ->leftJoinSub($latestSessionQuery, 's', 's.user_id', '=', 'users.id')
            ->where(function ($q) use ($companyId) {
                $q->where('users.company_id', $companyId)
                    ->orWhereExists(function ($inner) use ($companyId) {
                        $inner->select(DB::raw(1))
                            ->from('company_user')
                            ->whereColumn('company_user.user_id', 'users.id')
                            ->where('company_user.company_id', $companyId);
                    });
            });

        $result = $query->first();

        return [
            'total' => (int) ($result->total ?? 0),
            'online' => (int) ($result->online ?? 0),
            'never' => (int) ($result->never ?? 0),
        ];
    }
}
