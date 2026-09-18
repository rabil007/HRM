<?php

namespace App\Support\CrewMovements;

use App\Models\Employee;
use App\Models\User;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\Employees\ActiveEmployeeConstraint;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class CurrentCrewHomeQuery
{
    public const NEAR_LIMIT_DAYS = 7;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(int $companyId, array $filters = [], ?User $user = null): LengthAwarePaginator
    {
        $pool = self::resolvePool($companyId, $filters, $user);
        $sorted = self::sortPool($pool, CrewOperationsSettings::maxHomeDays($companyId));
        $perPage = CurrentCrewQuery::resolvePerPage($filters['per_page'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return array{
     *     on_home: int,
     *     on_home_over_limit: int,
     *     max_home_days: int
     * }
     */
    public static function summaryCounts(int $companyId, ?User $user = null): array
    {
        $maxHomeDays = CrewOperationsSettings::maxHomeDays($companyId);
        $pool = self::resolvePool($companyId, [], $user);
        $overLimit = $pool->filter(
            fn (array $item): bool => self::availabilityStatus(
                $item['days_at_home'],
                $maxHomeDays,
            ) === 'over_limit',
        )->count();

        return [
            'on_home' => $pool->count(),
            'on_home_over_limit' => $overLimit,
            'max_home_days' => $maxHomeDays,
        ];
    }

    public static function isOnHomeStatus(string $status): bool
    {
        return in_array($status, ['in_home', 'home_redeploy'], true);
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    public static function resolveDaysAtHome(array $resolved): ?int
    {
        if ($resolved['status'] === 'home_redeploy') {
            return isset($resolved['days_in_phase']) ? (int) $resolved['days_in_phase'] : null;
        }

        if ($resolved['status'] === 'in_home') {
            return isset($resolved['in_home_days']) ? (int) $resolved['in_home_days'] : null;
        }

        return null;
    }

    public static function availabilityStatus(?int $daysAtHome, int $maxHomeDays): string
    {
        if ($daysAtHome === null) {
            return 'within_limit';
        }

        if ($daysAtHome > $maxHomeDays) {
            return 'over_limit';
        }

        if ($daysAtHome > $maxHomeDays - self::NEAR_LIMIT_DAYS) {
            return 'near_limit';
        }

        return 'within_limit';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private static function resolvePool(int $companyId, array $filters, ?User $user = null): Collection
    {
        $query = ActiveEmployeeConstraint::apply(Employee::query(), $companyId)
            ->with(['rank:id,name']);

        if ($user !== null) {
            EmployeeVisibilityScope::apply($query, $user, $companyId);
        }

        self::applyFilters($query, $companyId, $filters);

        $employees = $query->orderBy('name')->get();

        if ($employees->isEmpty()) {
            return collect();
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $resolver = new CrewAssignmentStatusResolver;
        $statuses = $resolver->forEmployeeIds(
            $companyId,
            $employees->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $today,
        );

        return $employees
            ->filter(function (Employee $employee) use ($statuses): bool {
                $status = (string) ($statuses[(int) $employee->id]['status'] ?? '');

                return self::isOnHomeStatus($status);
            })
            ->map(function (Employee $employee) use ($statuses, $timezone): array {
                $resolved = $statuses[(int) $employee->id] ?? [];

                return [
                    'employee' => $employee,
                    'resolved' => $resolved,
                    'days_at_home' => self::resolveDaysAtHome($resolved),
                    'home_since' => $resolved['since'] ?? null,
                    'company_timezone' => $timezone,
                ];
            })
            ->values();
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  array<string, mixed>  $filters
     */
    private static function applyFilters(Builder $query, int $companyId, array $filters): void
    {
        if (! empty($filters['employee_id'])) {
            $query->whereKey((int) $filters['employee_id']);
        }

        if (! empty($filters['rank_id'])) {
            $query->where('rank_id', (int) $filters['rank_id']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $inner) use ($search, $companyId): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('employee_no', 'like', '%'.$search.'%')
                    ->orWhereHas('rank', fn (Builder $rank) => $rank->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('crewAssignments', function (Builder $assignment) use ($search, $companyId): void {
                        $assignment->where('company_id', $companyId)
                            ->where(function (Builder $match) use ($search): void {
                                $match->where('assignment_no', 'like', '%'.$search.'%')
                                    ->orWhereHas('vessel', fn (Builder $vessel) => $vessel->where('name', 'like', '%'.$search.'%'));
                            });
                    });
            });
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pool
     * @return Collection<int, array<string, mixed>>
     */
    private static function sortPool(Collection $pool, int $maxHomeDays): Collection
    {
        return $pool->sort(function (array $left, array $right) use ($maxHomeDays): int {
            $leftDays = $left['days_at_home'];
            $rightDays = $right['days_at_home'];
            $leftStatus = self::availabilityStatus($leftDays, $maxHomeDays);
            $rightStatus = self::availabilityStatus($rightDays, $maxHomeDays);
            $leftRank = self::urgencyRank($leftStatus, $leftDays, $maxHomeDays);
            $rightRank = self::urgencyRank($rightStatus, $rightDays, $maxHomeDays);

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            if ($leftDays !== null && $rightDays !== null && $leftDays !== $rightDays) {
                return $rightDays <=> $leftDays;
            }

            if ($leftDays === null && $rightDays !== null) {
                return 1;
            }

            if ($leftDays !== null && $rightDays === null) {
                return -1;
            }

            return strcasecmp(
                (string) ($left['employee']->name ?? ''),
                (string) ($right['employee']->name ?? ''),
            );
        })->values();
    }

    private static function urgencyRank(string $status, ?int $daysAtHome, int $maxHomeDays): int
    {
        if ($status === 'over_limit') {
            return 0;
        }

        if ($status === 'near_limit') {
            return 1;
        }

        if ($daysAtHome !== null) {
            return 2;
        }

        return 3;
    }
}
