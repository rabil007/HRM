<?php

namespace App\Support\CrewMovements;

use Illuminate\Http\Request;

final class CurrentCrewRequestFilters
{
    public const VIEW_CREW = 'crew';

    public const VIEW_VESSEL = 'vessel';

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'phase' => trim((string) $request->query('phase', '')),
            'status' => trim((string) $request->query('status', '')),
            'vessel_id' => $request->query('vessel_id'),
            'rank_id' => $request->query('rank_id'),
            'client_id' => $request->query('client_id'),
            'employee_id' => $request->query('employee_id'),
            'planned_join_from' => $request->query('planned_join_from'),
            'planned_join_to' => $request->query('planned_join_to'),
            'planned_signoff_from' => $request->query('planned_signoff_from'),
            'planned_signoff_to' => $request->query('planned_signoff_to'),
            'tour_status' => trim((string) $request->query('tour_status', '')),
            'relief_status' => trim((string) $request->query('relief_status', '')),
            'relief_risk' => trim((string) $request->query('relief_risk', '')),
            'relief_not_ready' => filter_var($request->query('relief_not_ready', false), FILTER_VALIDATE_BOOLEAN),
            'signoff_within_14_no_relief' => filter_var($request->query('signoff_within_14_no_relief', false), FILTER_VALIDATE_BOOLEAN),
            'movement_attention' => filter_var($request->query('movement_attention', false), FILTER_VALIDATE_BOOLEAN),
            'include_completed' => filter_var($request->query('include_completed', false), FILTER_VALIDATE_BOOLEAN),
            'sort' => $request->query('sort', 'created_at'),
            'direction' => $request->query('direction', 'desc'),
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
            'phase' => $filters['phase'],
            'status' => $filters['status'],
            'vessel_id' => $filters['vessel_id'] !== null && $filters['vessel_id'] !== '' ? (string) $filters['vessel_id'] : '',
            'rank_id' => $filters['rank_id'] !== null && $filters['rank_id'] !== '' ? (string) $filters['rank_id'] : '',
            'client_id' => $filters['client_id'] !== null && $filters['client_id'] !== '' ? (string) $filters['client_id'] : '',
            'employee_id' => $filters['employee_id'] !== null && $filters['employee_id'] !== '' ? (string) $filters['employee_id'] : '',
            'planned_join_from' => $filters['planned_join_from'] ? (string) $filters['planned_join_from'] : '',
            'planned_join_to' => $filters['planned_join_to'] ? (string) $filters['planned_join_to'] : '',
            'planned_signoff_from' => $filters['planned_signoff_from'] ? (string) $filters['planned_signoff_from'] : '',
            'planned_signoff_to' => $filters['planned_signoff_to'] ? (string) $filters['planned_signoff_to'] : '',
            'tour_status' => $filters['tour_status'],
            'relief_status' => $filters['relief_status'],
            'relief_risk' => $filters['relief_risk'],
            'relief_not_ready' => (bool) $filters['relief_not_ready'],
            'signoff_within_14_no_relief' => (bool) $filters['signoff_within_14_no_relief'],
            'movement_attention' => (bool) $filters['movement_attention'],
            'include_completed' => (bool) $filters['include_completed'],
        ];
    }

    public static function view(Request $request): string
    {
        return $request->query('view') === self::VIEW_VESSEL
            ? self::VIEW_VESSEL
            : self::VIEW_CREW;
    }
}
