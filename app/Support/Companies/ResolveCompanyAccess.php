<?php

namespace App\Support\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for whether a user may access a company.
 *
 * Accessible when the company is active and the user has an active company_user
 * membership, or (legacy only) users.company_id matches with no pivot row.
 */
final class ResolveCompanyAccess
{
    public function canAccess(User $user, int $companyId): bool
    {
        return in_array($companyId, $this->accessibleCompanyIds($user), true);
    }

    /**
     * @return list<int>
     */
    public function accessibleCompanyIds(User $user): array
    {
        $activeMembershipIds = $user->companies()
            ->where('companies.status', 'active')
            ->wherePivot('status', 'active')
            ->pluck('companies.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($user->company_id) {
            $homeCompanyId = (int) $user->company_id;

            if ($this->isLegacyHomeCompanyAccessible($user, $homeCompanyId)
                && ! in_array($homeCompanyId, $activeMembershipIds, true)) {
                $activeMembershipIds[] = $homeCompanyId;
            }
        }

        return array_values(array_unique($activeMembershipIds));
    }

    /**
     * Whether the user has company access via active pivot membership or the
     * legacy no-pivot home-company rule.
     */
    public function hasAccessibleMembership(User $user, int $companyId): bool
    {
        return $this->canAccess($user, $companyId);
    }

    /**
     * Batch membership check for many users against one company.
     *
     * @param  list<int>  $userIds
     * @return array<int, bool> keyed by user id
     */
    public function accessibleMembershipByUserId(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $companyIsActive = Company::query()
            ->whereKey($companyId)
            ->where('status', 'active')
            ->exists();

        if (! $companyIsActive) {
            return array_fill_keys($userIds, false);
        }

        $pivotRows = DB::table('company_user')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'status']);

        $pivotByUserId = [];
        foreach ($pivotRows as $row) {
            $pivotByUserId[(int) $row->user_id] = (string) $row->status;
        }

        $homeCompanyByUserId = User::query()
            ->whereIn('id', $userIds)
            ->pluck('company_id', 'id')
            ->map(fn ($id): ?int => $id !== null ? (int) $id : null)
            ->all();

        $map = [];
        foreach ($userIds as $userId) {
            if (array_key_exists($userId, $pivotByUserId)) {
                $map[$userId] = $pivotByUserId[$userId] === 'active';

                continue;
            }

            $map[$userId] = ($homeCompanyByUserId[$userId] ?? null) === $companyId;
        }

        return $map;
    }

    /**
     * Prefer home company when accessible, otherwise first accessible membership.
     *
     * @param  list<int>  $accessibleCompanyIds
     */
    public function resolveFallbackCompanyId(User $user, array $accessibleCompanyIds): ?int
    {
        if ($user->company_id && in_array((int) $user->company_id, $accessibleCompanyIds, true)) {
            return (int) $user->company_id;
        }

        return $accessibleCompanyIds[0] ?? null;
    }

    /**
     * @return Collection<int, Company>
     */
    public function accessibleCompanies(User $user): Collection
    {
        $ids = $this->accessibleCompanyIds($user);

        if ($ids === []) {
            return collect();
        }

        return Company::query()
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);
    }

    public function isLegacyHomeCompanyAccessible(User $user, int $companyId): bool
    {
        if (! $user->company_id || (int) $user->company_id !== $companyId) {
            return false;
        }

        $hasAnyPivot = $user->companies()->whereKey($companyId)->exists();

        if ($hasAnyPivot) {
            return false;
        }

        return Company::query()
            ->whereKey($companyId)
            ->where('status', 'active')
            ->exists();
    }
}
