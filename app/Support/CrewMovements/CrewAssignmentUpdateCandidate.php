<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves the effective assignment field set that will be persisted on update.
 *
 * Explicitly submitted blank nullable fields become null — they must not silently
 * fall back to the previous persisted value during validation while persistence writes null.
 */
final class CrewAssignmentUpdateCandidate
{
    /**
     * @param  array<string, mixed>  $submitted  Keys present in the write payload (has / array_key_exists)
     * @return array{
     *     rank_id: int|null,
     *     client_id: int|null,
     *     vessel_id: int|null,
     *     planned_arrival_at: CarbonInterface|null,
     *     planned_join_at: CarbonInterface|null,
     *     planned_signoff_at: CarbonInterface|null,
     *     remarks: string|null,
     * }
     */
    public static function resolve(CrewAssignment $assignment, array $submitted, string $timezone): array
    {
        return [
            'rank_id' => self::resolveInt($assignment->rank_id, $submitted, 'rank_id'),
            'client_id' => self::resolveInt($assignment->client_id, $submitted, 'client_id'),
            'vessel_id' => self::resolveInt($assignment->vessel_id, $submitted, 'vessel_id'),
            'planned_arrival_at' => self::resolveDate($assignment->planned_arrival_at, $submitted, 'planned_arrival_at', $timezone),
            'planned_join_at' => self::resolveDate($assignment->planned_join_at, $submitted, 'planned_join_at', $timezone),
            'planned_signoff_at' => self::resolveDate($assignment->planned_signoff_at, $submitted, 'planned_signoff_at', $timezone),
            'remarks' => self::resolveString($assignment->remarks, $submitted, 'remarks'),
        ];
    }

    /**
     * Attributes that should be written (only keys present in $submitted).
     *
     * @param  array<string, mixed>  $submitted
     * @param  array{
     *     rank_id: int|null,
     *     client_id: int|null,
     *     vessel_id: int|null,
     *     planned_arrival_at: CarbonInterface|null,
     *     planned_join_at: CarbonInterface|null,
     *     planned_signoff_at: CarbonInterface|null,
     *     remarks: string|null,
     * }  $candidate
     * @return array<string, mixed>
     */
    public static function persistableSlice(array $submitted, array $candidate): array
    {
        $out = [];

        foreach ([
            'rank_id',
            'client_id',
            'vessel_id',
            'planned_arrival_at',
            'planned_join_at',
            'planned_signoff_at',
            'remarks',
        ] as $key) {
            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            $out[$key] = $candidate[$key];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private static function resolveInt(mixed $existing, array $submitted, string $key): ?int
    {
        if (! array_key_exists($key, $submitted)) {
            return $existing !== null ? (int) $existing : null;
        }

        $value = $submitted[$key];

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private static function resolveDate(
        mixed $existing,
        array $submitted,
        string $key,
        string $timezone,
    ): ?CarbonInterface {
        if (! array_key_exists($key, $submitted)) {
            return $existing instanceof CarbonInterface ? $existing : null;
        }

        $value = $submitted[$key];

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return Carbon::parse((string) $value, $timezone);
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private static function resolveString(mixed $existing, array $submitted, string $key): ?string
    {
        if (! array_key_exists($key, $submitted)) {
            return $existing !== null ? (string) $existing : null;
        }

        $value = $submitted[$key];

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
