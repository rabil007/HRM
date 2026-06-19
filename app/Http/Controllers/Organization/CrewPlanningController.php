<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use App\Support\CrewPlanning\CrewPlanningPagePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewPlanningController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $from = $this->resolveDate($request->query('from'), CarbonImmutable::now()->startOfMonth()->toDateString());
        $to = $this->resolveDate($request->query('to'), CarbonImmutable::now()->addMonths(2)->endOfMonth()->toDateString());

        $vesselId = $request->query('vessel_id');
        $vesselId = $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null;

        $rankId = $request->query('rank_id');
        $rankId = $rankId !== null && $rankId !== '' ? (int) $rankId : null;

        $search = trim((string) $request->query('search', ''));

        return Inertia::render('organization/crew-planning/index', [
            'rows' => CrewPlanningGanttQuery::rows($companyId, $vesselId, $rankId),
            'bars' => CrewPlanningGanttQuery::bars($companyId, $from, $to, $vesselId, $rankId),
            'tree' => CrewPlanningGanttQuery::tree($companyId, $from, $to, $vesselId, $rankId),
            'filters' => [
                'vessel_id' => $vesselId,
                'rank_id' => $rankId,
                'from' => $from,
                'to' => $to,
                'search' => $search,
            ],
            'today' => CarbonImmutable::today()->toDateString(),
            'vessels' => $this->activeVessels(),
            'ranks' => $this->activeRanks(),
            'employees' => CrewOperationsSettings::poolEmployees($companyId),
            'can' => CrewPlanningPagePermissions::for($request->user()),
        ]);
    }

    private function resolveDate(mixed $value, string $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeVessels(): array
    {
        return Vessel::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeRanks(): array
    {
        return Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
