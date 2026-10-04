<?php

namespace App\Support\CrewOperations;

use App\Support\CrewMovements\CurrentCrewQuery;

final class CrewReadinessFilters
{
    public const WINDOW_7 = '7';

    public const WINDOW_14 = '14';

    public const WINDOW_30 = '30';

    public const WINDOW_ALL = 'all';

    public const SOURCE_ALL = 'all';

    public const SOURCE_PLANNING = 'planning';

    public const SOURCE_ASSIGNMENT = 'assignment';

    public const STATUS_ALL = 'all';

    public const STATUS_READY = 'ready';

    public const STATUS_ATTENTION = 'attention';

    public const STATUS_NOT_READY = 'not_ready';

    public const FOCUS_ALL = '';

    public const FOCUS_READY = 'ready';

    public const FOCUS_ATTENTION = 'attention';

    public const FOCUS_NOT_READY = 'not_ready';

    public const FOCUS_JOINING_7 = 'joining_7';

    public const FOCUS_NO_CHECKS = 'no_checks';

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     search: string|null,
     *     vessel_id: int|null,
     *     position_id: int|null,
     *     readiness_status: string,
     *     source: string,
     *     window: string,
     *     focus: string,
     *     per_page: int,
     *     page: int
     * }
     */
    public static function normalize(array $raw): array
    {
        $search = isset($raw['search']) && is_string($raw['search']) ? trim($raw['search']) : null;
        if ($search === '') {
            $search = null;
        }

        $vesselId = isset($raw['vessel_id']) && is_numeric($raw['vessel_id']) && (int) $raw['vessel_id'] > 0
            ? (int) $raw['vessel_id']
            : null;

        $positionId = isset($raw['position_id']) && is_numeric($raw['position_id']) && (int) $raw['position_id'] > 0
            ? (int) $raw['position_id']
            : null;

        $readinessStatus = isset($raw['readiness_status']) && in_array($raw['readiness_status'], [
            self::STATUS_ALL,
            self::STATUS_READY,
            self::STATUS_ATTENTION,
            self::STATUS_NOT_READY,
        ], true)
            ? (string) $raw['readiness_status']
            : self::STATUS_ALL;

        $source = isset($raw['source']) && in_array($raw['source'], [
            self::SOURCE_ALL,
            self::SOURCE_PLANNING,
            self::SOURCE_ASSIGNMENT,
        ], true)
            ? (string) $raw['source']
            : self::SOURCE_ALL;

        $window = isset($raw['window']) && in_array($raw['window'], [
            self::WINDOW_7,
            self::WINDOW_14,
            self::WINDOW_30,
            self::WINDOW_ALL,
        ], true)
            ? (string) $raw['window']
            : self::WINDOW_30;

        $focus = isset($raw['focus']) && in_array($raw['focus'], [
            self::FOCUS_READY,
            self::FOCUS_ATTENTION,
            self::FOCUS_NOT_READY,
            self::FOCUS_JOINING_7,
            self::FOCUS_NO_CHECKS,
        ], true)
            ? (string) $raw['focus']
            : '';

        $perPage = CurrentCrewQuery::resolvePerPage($raw['per_page'] ?? null);
        $page = isset($raw['page']) && is_numeric($raw['page']) ? max(1, (int) $raw['page']) : 1;

        return [
            'search' => $search,
            'vessel_id' => $vesselId,
            'position_id' => $positionId,
            'readiness_status' => $readinessStatus,
            'source' => $source,
            'window' => $window,
            'focus' => $focus,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public static function hasActiveQuery(array $normalized): bool
    {
        return ($normalized['search'] ?? null) !== null
            || ($normalized['vessel_id'] ?? null) !== null
            || ($normalized['position_id'] ?? null) !== null
            || ($normalized['readiness_status'] ?? self::STATUS_ALL) !== self::STATUS_ALL
            || ($normalized['source'] ?? self::SOURCE_ALL) !== self::SOURCE_ALL
            || ($normalized['window'] ?? self::WINDOW_30) !== self::WINDOW_30
            || ($normalized['focus'] ?? '') !== '';
    }
}
