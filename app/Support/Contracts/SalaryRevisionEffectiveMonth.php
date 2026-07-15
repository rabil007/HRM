<?php

namespace App\Support\Contracts;

use Carbon\Carbon;
use InvalidArgumentException;

final class SalaryRevisionEffectiveMonth
{
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Effective month cannot be empty.');
        }

        return Carbon::parse($trimmed)->startOfMonth()->toDateString();
    }

    public static function tryNormalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return self::normalize((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
