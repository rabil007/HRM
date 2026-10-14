<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Reports\CrewRelief\CrewReliefReportFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Compatibility redirect for retired Relief Desk bookmarks.
 */
final class CrewReliefDeskRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $allowed = [
            'search',
            'vessel_id',
            'position_id',
            'client_id',
            'focus',
            'relief_status',
            'relief_risk',
            'planned_signoff_from',
            'planned_signoff_to',
            'horizon',
            'page',
            'per_page',
            'readiness',
            'attention',
            'preset',
        ];

        $params = array_filter(
            $request->only($allowed),
            fn ($value): bool => $value !== null && $value !== '',
        );

        $horizon = (string) ($params['horizon'] ?? '');
        unset($params['horizon']);

        if ($horizon === 'all') {
            $params['preset'] = CrewReliefReportFilters::PRESET_ALL;
        }

        if (! isset($params['preset']) && $horizon === '') {
            $params['preset'] = CrewReliefReportFilters::PRESET_NEXT_30_DAYS;
        }

        return redirect()->route('organization.reports.crew-relief.index', $params);
    }
}
