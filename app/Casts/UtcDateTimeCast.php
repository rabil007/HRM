<?php

namespace App\Casts;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Persist and hydrate datetimes as UTC wall clocks, independent of APP_TIMEZONE.
 *
 * MySQL TIMESTAMP session conversion is avoided by storing these columns as DATETIME
 * (see crew_scheduled_movements migration). Values in the database are always UTC.
 *
 * Naive `Y-m-d H:i:s` strings are treated as UTC walls (already canonical), not APP_TIMEZONE.
 *
 * @implements CastsAttributes<Carbon|null, CarbonInterface|string|null>
 */
final class UtcDateTimeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->utc();
        }

        return Carbon::parse((string) $value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc()->format('Y-m-d H:i:s');
        }

        if (is_string($value) || is_numeric($value)) {
            $string = (string) $value;

            // Canonical UTC wall already produced by CrewScheduledMovementTimestamp::storeUtc
            // (or a raw DB digit string). Do not reinterpret in APP_TIMEZONE.
            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?$/', $string) === 1) {
                return Carbon::parse(str_replace('T', ' ', $string), 'UTC')->format('Y-m-d H:i:s');
            }

            return Carbon::parse($string)->utc()->format('Y-m-d H:i:s');
        }

        throw new InvalidArgumentException("Invalid datetime value for [{$key}].");
    }
}
