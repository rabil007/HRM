<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewProjectedManningPagePermissions;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrewProjectedManningController extends Controller
{
    private const array HORIZONS = [30, 60, 90];

    public function __invoke(Request $request, CrewProjectedManningQuery $query): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $timezone = CompanyTimezone::forCompanyId($companyId);

        $request->merge([
            'horizon' => $this->nullableQuery($request, 'horizon'),
            'vessel_id' => $this->nullableQuery($request, 'vessel_id'),
            'rank_id' => $this->nullableQuery($request, 'rank_id'),
        ]);

        $validated = $request->validate([
            'horizon' => ['nullable', 'integer', Rule::in(self::HORIZONS)],
            'vessel_id' => ['nullable', 'integer', 'min:1'],
            'rank_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $horizon = (int) ($validated['horizon'] ?? 30);
        $vesselId = isset($validated['vessel_id']) ? (int) $validated['vessel_id'] : null;
        $rankId = isset($validated['rank_id']) ? (int) $validated['rank_id'] : null;

        $from = CarbonImmutable::now($timezone)->toDateString();
        $to = CarbonImmutable::parse($from, $timezone)->addDays($horizon)->toDateString();

        $projection = $query->forCompany(
            $companyId,
            $from,
            $to,
            $vesselId,
            $rankId,
        );

        return Inertia::render('organization/crew-operations/projected-manning', [
            'from' => $projection['from'],
            'to' => $projection['to'],
            'company_timezone' => $projection['company_timezone'],
            'summary' => $projection['summary'],
            'items' => $projection['items'],
            'filters' => [
                'horizon' => $horizon,
                'vessel_id' => $vesselId,
                'rank_id' => $rankId,
            ],
            'horizons' => self::HORIZONS,
            'vessels' => $this->filterVessels($companyId),
            'ranks' => $this->filterRanks($companyId),
            'can' => CrewProjectedManningPagePermissions::for($request->user()),
        ]);
    }

    private function nullableQuery(Request $request, string $key): mixed
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function filterVessels(int $companyId): array
    {
        $vesselIds = VesselManning::query()
            ->where('company_id', $companyId)
            ->distinct()
            ->pluck('vessel_id')
            ->all();

        if ($vesselIds === []) {
            return [];
        }

        return Vessel::query()
            ->whereIn('id', $vesselIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function filterRanks(int $companyId): array
    {
        $rankIds = VesselManning::query()
            ->where('company_id', $companyId)
            ->distinct()
            ->pluck('rank_id')
            ->all();

        if ($rankIds === []) {
            return [];
        }

        return Rank::query()
            ->whereIn('id', $rankIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
