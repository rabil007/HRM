<?php

namespace App\Support\EmployeeDocuments;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class DocumentExpiry
{
    public static function resolve(CarbonInterface|string|null $expiryDate, ?CarbonInterface $today = null): ?DocumentExpiryStatus
    {
        $expiry = self::parseDate($expiryDate);

        if ($expiry === null) {
            return null;
        }

        $today = self::startOfDay($today ?? now());
        $expiryDay = $expiry->copy()->startOfDay();

        if ($expiryDay->lt($today)) {
            return DocumentExpiryStatus::Expired;
        }

        $daysUntilExpiry = $today->diffInDays($expiryDay, false);

        if ($daysUntilExpiry <= 7) {
            return DocumentExpiryStatus::Expiring7;
        }

        if ($daysUntilExpiry <= 15) {
            return DocumentExpiryStatus::Expiring15;
        }

        if ($daysUntilExpiry <= 30) {
            return DocumentExpiryStatus::Expiring30;
        }

        return DocumentExpiryStatus::Valid;
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
            return 'No expiry';
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
            DocumentExpiryStatus::Expired->value,
            DocumentExpiryStatus::Expiring30->value,
            DocumentExpiryStatus::Expiring15->value,
            DocumentExpiryStatus::Expiring7->value,
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
