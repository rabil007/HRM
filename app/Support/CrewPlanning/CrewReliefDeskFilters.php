<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use Illuminate\Http\Request;

final class CrewReliefDeskFilters
{
    public const HORIZON_DEFAULT = '30';

    public const HORIZON_ALL = 'all';

    /**
     * @return list<string>
     */
    public static function focusValues(): array
    {
        return [
            'needs_relief',
            'critical',
            'not_ready',
            'signoff_7',
            'signoff_14',
            'ready',
            'overdue',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'search' => '',
            'vessel_id' => null,
            'rank_id' => null,
            'client_id' => null,
            'relief_status' => '',
            'relief_risk' => '',
            'planned_signoff_from' => '',
            'planned_signoff_to' => '',
            'horizon' => self::HORIZON_DEFAULT,
            'focus' => '',
            'per_page' => 15,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        $vesselId = $request->query('vessel_id');
        $rankId = $request->query('rank_id');
        $clientId = $request->query('client_id');
        $horizon = trim((string) $request->query('horizon', self::HORIZON_DEFAULT));
        $focus = trim((string) $request->query('focus', ''));
        $reliefStatus = trim((string) $request->query('relief_status', ''));
        $reliefRisk = trim((string) $request->query('relief_risk', ''));

        if ($horizon !== self::HORIZON_ALL) {
            $horizon = self::HORIZON_DEFAULT;
        }

        if (! in_array($focus, self::focusValues(), true)) {
            $focus = '';
        }

        if (CrewReliefStatus::tryFrom($reliefStatus) === null) {
            $reliefStatus = '';
        }

        if (CrewReliefRisk::tryFrom($reliefRisk) === null) {
            $reliefRisk = '';
        }

        return [
            'search' => trim((string) $request->query('search', '')),
            'vessel_id' => $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null,
            'rank_id' => $rankId !== null && $rankId !== '' ? (int) $rankId : null,
            'client_id' => $clientId !== null && $clientId !== '' ? (int) $clientId : null,
            'relief_status' => $reliefStatus,
            'relief_risk' => $reliefRisk,
            'planned_signoff_from' => trim((string) $request->query('planned_signoff_from', '')),
            'planned_signoff_to' => trim((string) $request->query('planned_signoff_to', '')),
            'horizon' => $horizon,
            'focus' => $focus,
            'per_page' => $request->query('per_page'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function inertiaFilters(array $filters): array
    {
        return [
            'search' => (string) ($filters['search'] ?? ''),
            'vessel_id' => $filters['vessel_id'] !== null && $filters['vessel_id'] !== ''
                ? (int) $filters['vessel_id']
                : null,
            'rank_id' => $filters['rank_id'] !== null && $filters['rank_id'] !== ''
                ? (int) $filters['rank_id']
                : null,
            'client_id' => $filters['client_id'] !== null && $filters['client_id'] !== ''
                ? (int) $filters['client_id']
                : null,
            'relief_status' => (string) ($filters['relief_status'] ?? ''),
            'relief_risk' => (string) ($filters['relief_risk'] ?? ''),
            'planned_signoff_from' => (string) ($filters['planned_signoff_from'] ?? ''),
            'planned_signoff_to' => (string) ($filters['planned_signoff_to'] ?? ''),
            'horizon' => (string) ($filters['horizon'] ?? self::HORIZON_DEFAULT),
            'focus' => (string) ($filters['focus'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function hasActiveQuery(array $filters): bool
    {
        return trim((string) ($filters['search'] ?? '')) !== ''
            || ($filters['vessel_id'] ?? null) !== null
            || ($filters['rank_id'] ?? null) !== null
            || ($filters['client_id'] ?? null) !== null
            || trim((string) ($filters['relief_status'] ?? '')) !== ''
            || trim((string) ($filters['relief_risk'] ?? '')) !== ''
            || trim((string) ($filters['planned_signoff_from'] ?? '')) !== ''
            || trim((string) ($filters['planned_signoff_to'] ?? '')) !== ''
            || trim((string) ($filters['focus'] ?? '')) !== ''
            || (($filters['horizon'] ?? self::HORIZON_DEFAULT) === self::HORIZON_ALL);
    }
}
