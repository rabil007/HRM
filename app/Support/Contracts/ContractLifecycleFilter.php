<?php

namespace App\Support\Contracts;

use App\Models\EmployeeContract;
use Illuminate\Database\Eloquent\Builder;

final class ContractLifecycleFilter
{
    public const ALL = 'all';

    public const ACTIVE = 'active';

    public const ENDING_30 = 'ending_30';

    public const ENDING_60 = 'ending_60';

    public const ENDING_90 = 'ending_90';

    public const ENDED = 'ended';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::ALL,
            self::ACTIVE,
            self::ENDING_30,
            self::ENDING_60,
            self::ENDING_90,
            self::ENDED,
        ];
    }

    public static function isValid(?string $filter): bool
    {
        return in_array($filter, self::values(), true);
    }

    /**
     * @param  Builder<EmployeeContract>  $query
     */
    public static function apply(Builder $query, string $filter): void
    {
        if ($filter === self::ALL) {
            return;
        }

        match ($filter) {
            self::ACTIVE => $query->where('status', 'active'),
            self::ENDED => $query->where('status', 'ended'),
            self::ENDING_30 => self::applyEndingWithin($query, 30),
            self::ENDING_60 => self::applyEndingWithin($query, 60),
            self::ENDING_90 => self::applyEndingWithin($query, 90),
            default => null,
        };
    }

    /**
     * @param  Builder<EmployeeContract>  $query
     */
    private static function applyEndingWithin(Builder $query, int $days): void
    {
        $today = now()->toDateString();
        $until = now()->addDays($days)->toDateString();

        $query
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $until);
    }
}
