<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserEmailIdentity
{
    /**
     * Canonicalize an email the same way Fortify does when
     * `fortify.lowercase_usernames` is enabled.
     */
    public static function normalize(string $email): string
    {
        $email = trim($email);

        if (config('fortify.lowercase_usernames')) {
            return Str::lower($email);
        }

        return $email;
    }

    /**
     * Mask an email for operator output that may be captured in logs.
     */
    public static function mask(string $email): string
    {
        $normalized = self::normalize($email);
        $parts = explode('@', $normalized, 2);
        $local = $parts[0];
        $domain = $parts[1] ?? '';
        $visible = $local === '' ? '*' : substr($local, 0, 1);

        return $visible.'***@'.$domain;
    }

    /**
     * Non-deleted User rows whose stored email matches the Fortify-normalized value.
     *
     * Soft-deleted users are excluded so they cannot authenticate or block a
     * remaining live identity. Comparison uses LOWER(email) to match Fortify's
     * lowercase username canonicalization without a second normalization scheme.
     *
     * @return Builder<User>
     */
    public function matchingNonDeleted(string $email): Builder
    {
        return User::query()->whereRaw('LOWER(email) = ?', [self::normalize($email)]);
    }

    /**
     * Resolve the login/reset identity for an email.
     *
     * 0 matches => null (normal unknown account)
     * 1 match => that User
     * 2+ matches => null (fail closed; same caller-visible result as 0)
     */
    public function findUnique(string $email): ?User
    {
        $matches = $this->matchingNonDeleted($email)->limit(2)->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    /**
     * Read-only duplicate groups among non-deleted User rows.
     *
     * @return list<array{
     *     normalized_email: string,
     *     identity_count: int,
     *     users: list<array{
     *         id: int,
     *         email: string,
     *         status: string|null,
     *         home_company_id: int|null,
     *         membership_count: int,
     *         memberships: list<array{company_id: int, status: string|null}>,
     *         employee_link_count: int,
     *         role_assignment_count: int
     *     }>
     * }>
     */
    public function duplicateGroups(): array
    {
        $rows = User::query()
            ->toBase()
            ->selectRaw('LOWER(email) as normalized_email')
            ->selectRaw('COUNT(*) as identity_count')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('LOWER(email)')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var list<string> $normalizedEmails */
        $normalizedEmails = $rows->pluck('normalized_email')->map(fn ($email): string => (string) $email)->all();

        $users = User::query()
            ->where(function (Builder $query) use ($normalizedEmails): void {
                foreach ($normalizedEmails as $normalizedEmail) {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }
            })
            ->with('companies')
            ->withCount('employee')
            ->orderBy('id')
            ->get();

        $roleCounts = $this->roleAssignmentCounts($users->modelKeys());

        $usersByEmail = $users->groupBy(fn (User $user): string => self::normalize((string) $user->email));

        $groups = [];

        foreach ($rows as $row) {
            $normalizedEmail = (string) $row->normalized_email;
            $groupUsers = $usersByEmail->get($normalizedEmail, collect());

            $groups[] = [
                'normalized_email' => $normalizedEmail,
                'identity_count' => (int) $row->identity_count,
                'users' => $groupUsers->map(function (User $user) use ($roleCounts): array {
                    $memberships = $user->companies
                        ->map(fn ($company): array => [
                            'company_id' => (int) $company->id,
                            'status' => $company->pivot->status !== null ? (string) $company->pivot->status : null,
                        ])
                        ->values()
                        ->all();

                    return [
                        'id' => (int) $user->id,
                        'email' => (string) $user->email,
                        'status' => $user->status !== null ? (string) $user->status : null,
                        'home_company_id' => $user->company_id !== null ? (int) $user->company_id : null,
                        'membership_count' => count($memberships),
                        'memberships' => $memberships,
                        'employee_link_count' => (int) $user->employee_count,
                        'role_assignment_count' => (int) ($roleCounts[(int) $user->id] ?? 0),
                    ];
                })->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * @param  list<int|string>  $userIds
     * @return array<int, int>
     */
    private function roleAssignmentCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $table = config('permission.table_names.model_has_roles', 'model_has_roles');

        return DB::table($table)
            ->selectRaw('model_id, COUNT(*) as role_assignment_count')
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds)
            ->groupBy('model_id')
            ->pluck('role_assignment_count', 'model_id')
            ->mapWithKeys(fn ($count, $id): array => [(int) $id => (int) $count])
            ->all();
    }
}
