<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\CrewAssignment;
use App\Models\Rank;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use App\Support\CrewPlanning\CrewPlanningPagePermissions;
use App\Support\CrewPlanning\CrewPlanningProjectionPresenter;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Vessels\ResolvesCompanyVessels;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CrewPlanningController extends Controller
{
    use ResolvesPerPage;

    public const VIEW_PLANNING = 'planning';

    public const VIEW_ONBOARD_VESSELS = 'onboard-vessels';

    public function __construct(
        private readonly CrewProjectedManningQuery $projectedManningQuery,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $view = $this->resolveView($request);

        $from = $this->resolveDate($request->query('from'), CarbonImmutable::now()->startOfMonth()->toDateString());
        $to = $this->resolveDate($request->query('to'), CarbonImmutable::now()->addMonths(2)->endOfMonth()->toDateString());

        $vesselId = $request->query('vessel_id');
        $vesselId = $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null;

        $rankId = $request->query('rank_id');
        $rankId = $rankId !== null && $rankId !== '' ? (int) $rankId : null;

        $search = trim((string) $request->query('search', ''));
        $can = CrewPlanningPagePermissions::for($request->user());

        $shared = [
            'view' => $view,
            'filters' => [
                'vessel_id' => $vesselId,
                'rank_id' => $rankId,
                'from' => $from,
                'to' => $to,
                'search' => $search,
            ],
            'today' => CarbonImmutable::today()->toDateString(),
            'vessels' => $this->activeVessels($companyId),
            'ranks' => $this->activeRanks(),
            'can' => $can,
        ];

        if ($view === self::VIEW_ONBOARD_VESSELS) {
            Gate::authorize('viewAny', CrewAssignment::class);

            $onboardFilters = CurrentCrewRequestFilters::fromRequest($request);
            $paginator = CurrentCrewVesselQuery::paginate($companyId, $onboardFilters);

            return Inertia::render('organization/crew-planning/index', [
                ...$shared,
                'rows' => [],
                'bars' => [],
                'tree' => [],
                'employees' => [],
                'projection' => null,
                'relief_prefill' => null,
                'onboard_vessels' => $paginator->items(),
                'onboard_pagination' => $this->paginationMeta($paginator),
            ]);
        }

        $projection = null;
        $projectionPositions = null;

        if ($can['projection']) {
            $projection = CrewPlanningProjectionPresenter::present(
                $this->projectedManningQuery->forCompany(
                    $companyId,
                    $from,
                    $to,
                    $vesselId,
                    $rankId,
                ),
            );
            $projectionPositions = $projection['rows'];
        }

        return Inertia::render('organization/crew-planning/index', [
            ...$shared,
            'rows' => CrewPlanningGanttQuery::rows(
                $companyId,
                $from,
                $to,
                $vesselId,
                $rankId,
                $projectionPositions,
            ),
            'bars' => CrewPlanningGanttQuery::bars($companyId, $from, $to, $vesselId, $rankId),
            'tree' => CrewPlanningGanttQuery::tree(
                $companyId,
                $from,
                $to,
                $vesselId,
                $rankId,
                $projectionPositions,
            ),
            'employees' => CrewOperationsSettings::poolEmployees($companyId),
            'projection' => $projection,
            'relief_prefill' => $this->reliefPrefill($request, $companyId),
            'onboard_vessels' => [],
            'onboard_pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 15,
                'total' => 0,
                'from' => null,
                'to' => null,
            ],
        ]);
    }

    private function resolveView(Request $request): string
    {
        return $request->query('view') === self::VIEW_ONBOARD_VESSELS
            ? self::VIEW_ONBOARD_VESSELS
            : self::VIEW_PLANNING;
    }

    /**
     * @return array{
     *     vessel_id: int|null,
     *     rank_id: int|null,
     *     relieves_crew_assignment_id: int|null,
     *     planned_join_date: string|null,
     *     open_create: bool,
     *     relieves_employee_name: string|null
     * }|null
     */
    private function reliefPrefill(Request $request, int $companyId): ?array
    {
        $openCreate = filter_var($request->query('open_create', false), FILTER_VALIDATE_BOOLEAN);
        $relievesIdRaw = $request->query('relieves_crew_assignment_id');
        $relievesId = $relievesIdRaw !== null && $relievesIdRaw !== '' ? (int) $relievesIdRaw : null;

        $vesselIdRaw = $request->query('vessel_id');
        $vesselId = $vesselIdRaw !== null && $vesselIdRaw !== '' ? (int) $vesselIdRaw : null;

        $rankIdRaw = $request->query('rank_id');
        $rankId = $rankIdRaw !== null && $rankIdRaw !== '' ? (int) $rankIdRaw : null;

        $plannedJoinDate = $this->nullableDate($request->query('planned_join_date'));

        if (! $openCreate && $relievesId === null && $plannedJoinDate === null) {
            return null;
        }

        $relievesEmployeeName = null;

        if ($relievesId !== null) {
            $source = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->with(['employee:id,name'])
                ->find($relievesId);

            if ($source === null) {
                $relievesId = null;
            } else {
                $relievesEmployeeName = $source->employee?->name;
                $vesselId ??= $source->vessel_id !== null ? (int) $source->vessel_id : null;
                $rankId ??= $source->rank_id !== null ? (int) $source->rank_id : null;
                $plannedJoinDate ??= $source->planned_signoff_at?->toDateString();
            }
        }

        return [
            'vessel_id' => $vesselId,
            'rank_id' => $rankId,
            'relieves_crew_assignment_id' => $relievesId,
            'planned_join_date' => $plannedJoinDate,
            'open_create' => $openCreate,
            'relieves_employee_name' => $relievesEmployeeName,
        ];
    }

    private function nullableDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
    private function activeVessels(int $companyId): array
    {
        return ResolvesCompanyVessels::activeOptions($companyId);
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
