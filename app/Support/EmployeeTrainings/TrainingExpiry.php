<?php

namespace App\Support\EmployeeTrainings;

use App\Models\EmployeeTraining;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class TrainingExpiry
{
    /**
     * @param  Builder<EmployeeTraining>  $query
     * @return Builder<EmployeeTraining>
     */
    public static function applyExpiryFilter(Builder $query, string $filter): Builder
    {
        $today = now()->toDateString();

        return match ($filter) {
            TrainingExpiryStatus::Expired->value => $query
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', $today),
            TrainingExpiryStatus::Expiring30->value => self::whereExpiringWithin($query, 30),
            TrainingExpiryStatus::Expiring15->value => self::whereExpiringWithin($query, 15),
            TrainingExpiryStatus::Expiring7->value => self::whereExpiringWithin($query, 7),
            default => $query,
        };
    }

    /**
     * @param  Builder<EmployeeTraining>  $query
     * @return Builder<EmployeeTraining>
     */
    public static function whereExpiringWithin(Builder $query, int $days): Builder
    {
        $today = now()->toDateString();

        return $query
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
    }

    public static function resolve(CarbonInterface|string|null $expiryDate, ?CarbonInterface $today = null): ?TrainingExpiryStatus
    {
        $expiry = self::parseDate($expiryDate);

        if ($expiry === null) {
            return null;
        }

        $today = self::startOfDay($today ?? now());
        $expiryDay = $expiry->copy()->startOfDay();

        if ($expiryDay->lt($today)) {
            return TrainingExpiryStatus::Expired;
        }

        $daysUntilExpiry = $today->diffInDays($expiryDay, false);

        if ($daysUntilExpiry <= 7) {
            return TrainingExpiryStatus::Expiring7;
        }

        if ($daysUntilExpiry <= 15) {
            return TrainingExpiryStatus::Expiring15;
        }

        if ($daysUntilExpiry <= 30) {
            return TrainingExpiryStatus::Expiring30;
        }

        return TrainingExpiryStatus::Valid;
    }

    public static function remainingDays(CarbonInterface|string|null $expiryDate, ?CarbonInterface $today = null): ?int
    {
        $expiry = self::parseDate($expiryDate);

        if ($expiry === null) {
            return null;
        }

        $today = self::startOfDay($today ?? now());
        $expiryDay = $expiry->copy()->startOfDay();

        return (int) $today->diffInDays($expiryDay, false);
    }

    public static function humanLabel(CarbonInterface|string|null $expiryDate, ?CarbonInterface $today = null): string
    {
        $remaining = self::remainingDays($expiryDate, $today);

        if ($remaining === null) {
            return 'No Expiry';
        }

        if ($remaining < -1) {
            return 'Expired '.abs($remaining).' days ago';
        }

        if ($remaining === -1) {
            return 'Expired yesterday';
        }

        if ($remaining === 0) {
            return 'Expires today';
        }

        if ($remaining === 1) {
            return 'Expires tomorrow';
        }

        return "Expires in {$remaining} days";
    }

    public static function isValidFilter(?string $filter): bool
    {
        return in_array($filter, [
            'all',
            TrainingExpiryStatus::Expired->value,
            TrainingExpiryStatus::Expiring30->value,
            TrainingExpiryStatus::Expiring15->value,
            TrainingExpiryStatus::Expiring7->value,
        ], true);
    }

    private static function parseDate(CarbonInterface|string|null $expiryDate): ?Carbon
    {
        if ($expiryDate === null || $expiryDate === '') {
            return null;
        }

        return Carbon::parse($expiryDate)->startOfDay();
    }

    private static function startOfDay(CarbonInterface $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }
}
