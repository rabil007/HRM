<?php

namespace App\Http\Controllers\Organization;

use App\Enums\CrewAssignmentSubmissionIntent;
use App\Enums\CrewPhaseCode;
use App\Enums\RecentItemType;
use App\Enums\SavedViewPage;
use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreCrewAssignmentRequest;
use App\Http\Requests\Organization\UpdateCrewAssignmentRequest;
use App\Models\Client;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Hotel;
use App\Models\Rank;
use App\Models\RoomType;
use App\Support\Activity\RecentActivityQuery;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPresenter;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentCreateFormOptions;
use App\Support\CrewMovements\CrewAssignmentEditability;
use App\Support\CrewMovements\CrewAssignmentPagePermissions;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewHomePresenter;
use App\Support\CrewMovements\CurrentCrewHomeQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use App\Support\CrewPlanning\ResolvePlanningStartHandoff;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\RecentItems\RecordRecentItem;
use App\Support\SavedViews\ApplyDefaultSavedView;
use App\Support\SavedViews\SavedViewsForPage;
use App\Support\Vessels\ResolvesCompanyVessels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CrewAssignmentController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private CrewMovementService $service,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
    ) {}

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        Gate::authorize('viewAny', CrewAssignment::class);

        $redirect = ApplyDefaultSavedView::maybeRedirect($request, SavedViewPage::Crew);

        if ($redirect !== null) {
            return $redirect;
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $view = CurrentCrewRequestFilters::view($request);
        $filters = CurrentCrewRequestFilters::sanitizeForView(
            CurrentCrewRequestFilters::fromRequest($request),
            $view,
        );

        if ($view === CurrentCrewRequestFilters::VIEW_VESSEL) {
            $vesselPaginator = CurrentCrewVesselQuery::paginate($companyId, $filters);
            $assignments = [];
            $homeCrew = [];
            $vessels = $vesselPaginator->items();
            $pagination = $this->paginationMeta($vesselPaginator);
        } elseif ($view === CurrentCrewRequestFilters::VIEW_ON_HOME) {
            $filters['page'] = max(1, (int) $request->query('page', 1));
            $homePaginator = CurrentCrewHomeQuery::paginate($companyId, $filters);
            $assignments = [];
            $vessels = [];
            $homeCrew = collect($homePaginator->items())
                ->map(fn (array $item): array => CurrentCrewHomePresenter::listItem(
                    $item,
                    $companyId,
                    $request->user(),
                ))
                ->all();
            $pagination = $this->paginationMeta($homePaginator);
        } else {
            $paginator = CurrentCrewQuery::paginate($companyId, $filters, $view);
            $assignments = $paginator->through(
                fn (CrewAssignment $assignment) => CrewAssignmentPresenter::listItem($assignment, $request->user()),
            )->items();
            $homeCrew = [];
            $vessels = [];
            $pagination = $this->paginationMeta($paginator);
        }

        $summary = CrewMovementAttentionQuery::summaryCounts($companyId);
        $filterOptions = CurrentCrewQuery::filterOptions($companyId);

        return Inertia::render('organization/crew/index', [
            'view' => $view,
            'assignments' => $assignments,
            'home_crew' => $homeCrew ?? [],
            'vessels' => $vessels,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'filters' => CurrentCrewRequestFilters::inertiaFilters($filters, $view),
            'summary' => $summary,
            'filter_options' => $filterOptions,
            'form_options' => $this->movementFormOptions($companyId),
            'can' => CrewAssignmentPagePermissions::for($request->user()),
            'saved_views' => SavedViewsForPage::props($request->user(), $companyId, SavedViewPage::Crew),
        ]);
    }

    public function create(Request $request, ResolvePlanningStartHandoff $planningHandoff)
    {
        Gate::authorize('create', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $permissions = CrewAssignmentPagePermissions::for($request->user());
        $initialRowCount = $request->query('mode') === 'bulk' && $permissions['start'] ? 2 : 1;
        $planningContext = null;
        $planningBackQuery = [];

        $planningAssignmentId = $request->query('planning_assignment_id');

        if ($planningAssignmentId !== null && $planningAssignmentId !== '') {
            if (! $permissions['start'] || ! $request->user()?->can('crew_operations.planning.view')) {
                abort(403);
            }

            $planning = CrewPlanningAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $planningAssignmentId)
                ->firstOrFail();

            $linked = $planningHandoff->linkedAssignment($planning);

            if ($linked !== null) {
                $message = $planningHandoff->redirectMessageForLinked($linked);

                if (Gate::allows('view', $linked)) {
                    return redirect()
                        ->route('organization.crew-assignments.show', $linked)
                        ->with('success', $message);
                }

                return redirect()
                    ->route('organization.crew-planning.index')
                    ->with('error', $message);
            }

            try {
                $planningContext = $planningHandoff->prefill($planning, $companyId);
            } catch (CrewMovementException $exception) {
                return redirect()
                    ->route('organization.crew-planning.index')
                    ->with('error', $exception->getMessage());
            }

            $planningBackQuery = array_filter([
                'view' => $request->query('view'),
                'vessel_id' => $request->query('vessel_id'),
                'rank_id' => $request->query('rank_id'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'search' => $request->query('search'),
            ], fn ($value) => $value !== null && $value !== '');

            $initialRowCount = 1;
        }

        return Inertia::render('organization/crew/create', [
            'form_options' => CrewAssignmentCreateFormOptions::for($companyId, $request->user()),
            'can' => CrewAssignmentPagePermissions::for($request->user()),
            'initial_row_count' => $initialRowCount,
            'planning_context' => $planningContext,
            'planning_back_query' => $planningBackQuery !== [] ? $planningBackQuery : null,
        ]);
    }

    public function store(StoreCrewAssignmentRequest $request)
    {
        $intent = $request->submissionIntent();

        if ($intent === CrewAssignmentSubmissionIntent::Start) {
            Gate::authorize('start', CrewAssignment::class);
        } else {
            Gate::authorize('create', CrewAssignment::class);
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        try {
            $assignment = $intent === CrewAssignmentSubmissionIntent::Start
                ? $this->service->startAssignment(
                    $companyId,
                    (int) $validated['employee_id'],
                    [
                        'rank_id' => $validated['rank_id'] ?? null,
                        'client_id' => $validated['client_id'] ?? null,
                        'vessel_id' => $validated['vessel_id'] ?? null,
                        'planned_join_at' => $validated['planned_join_at'] ?? null,
                        'planned_arrival_at' => $validated['planned_arrival_at'] ?? null,
                        'current_stage' => CrewPhaseCode::PreMobilisation->value,
                        'remarks' => $validated['remarks'] ?? null,
                    ],
                    $request->user()?->id,
                )
                : DB::transaction(function () use ($companyId, $validated, $request) {
                    $assignment = $this->service->createDraft(
                        $companyId,
                        (int) $validated['employee_id'],
                        [
                            'rank_id' => $validated['rank_id'] ?? null,
                            'client_id' => $validated['client_id'] ?? null,
                            'vessel_id' => $validated['vessel_id'] ?? null,
                            'planned_join_at' => $validated['planned_join_at'] ?? null,
                            'planned_arrival_at' => $validated['planned_arrival_at'] ?? null,
                            'remarks' => $validated['remarks'] ?? null,
                        ],
                        $request->user()?->id,
                    );

                    $this->planningSync->sync($assignment);

                    return $assignment->fresh() ?? $assignment;
                });

            $success = $intent === CrewAssignmentSubmissionIntent::Start
                ? 'Crew assignment started successfully.'
                : 'Crew assignment created successfully.';

            if (Gate::allows('view', $assignment)) {
                return redirect()
                    ->route('organization.crew-assignments.show', $assignment)
                    ->with('success', $success);
            }

            return redirect()
                ->route('dashboard')
                ->with('success', $success);
        } catch (CrewMovementException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }
    }

    public function show(Request $request, CrewAssignment $assignment, RecordRecentItem $recordRecentItem)
    {
        Gate::authorize('view', $assignment);

        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $user = $request->user();
        if ($user !== null) {
            $recordRecentItem->handle($user, $companyId, RecentItemType::CrewAssignment, $assignment->id);
        }

        $assignment->load([
            'company:id,timezone',
            'employee',
            'rank',
            'client',
            'vessel',
            'currentPhase',
            'accommodationStays.hotel',
            'accommodationStays.roomType',
            'phases.pendingCorrections',
            'phases.corrections' => fn ($query) => $query->where('status', 'approved')->latest('decided_at'),
            'phases.employeeTraining:id,source_crew_assignment_phase_id',
            'planningAssignment.relievedAssignment.employee',
            'planningAssignment.relievedAssignment.vessel',
            'planningAssignment.relievedAssignment.rank',
            'previousAssignment:id,assignment_no,status,vessel_id,source,closed_at',
            'previousAssignment.vessel:id,name',
            'nextAssignments:id,assignment_no,status,vessel_id,source,previous_assignment_id,started_at',
            'nextAssignments.vessel:id,name',
            'corrections.requester:id,name',
            'corrections.decisionMaker:id,name',
            'corrections.phase',
            'corrections.company:id,timezone',
        ]);

        $detail = CrewAssignmentPresenter::detail($assignment, $request->user());
        $corrections = app(CrewMovementCorrectionPresenter::class)->assignmentSummary($assignment);

        $recentActivity = Gate::allows('viewAudit', CrewAssignment::class)
            ? RecentActivityQuery::for($request->user(), $companyId, CrewAssignment::class, $assignment->id)
            : [];

        return Inertia::render('organization/crew/show', [
            'assignment' => $detail,
            'corrections' => $corrections,
            'recent_activity' => $recentActivity,
            'form_options' => $this->movementFormOptions($companyId),
            'can' => CrewAssignmentPagePermissions::for($request->user()),
        ]);
    }

    public function edit(Request $request, CrewAssignment $assignment)
    {
        Gate::authorize('update', $assignment);

        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        if (! CrewAssignmentEditability::isEditable($assignment)) {
            return redirect()
                ->route('organization.crew-assignments.show', $assignment)
                ->with('error', 'This assignment can no longer be edited directly. Use Movement Actions or Request Correction.');
        }

        $assignment->load([
            'employee',
            'rank',
            'client',
            'vessel',
            'currentPhase',
            'phases.employeeTraining:id,source_crew_assignment_phase_id',
        ]);

        $formOptions = [
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'employee_no', 'rank_id'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'employee_no' => $e->employee_no,
                    'rank_id' => $e->rank_id,
                ])
                ->values()
                ->all(),
            'ranks' => $this->activeRanks(),
            'vessels' => $this->vesselOptionsForAssignment($companyId, $assignment),
            'clients' => $this->clientOptionsForAssignment($assignment),
            'courses' => $this->activeCourses(),
        ];

        return Inertia::render('organization/crew/edit', [
            'assignment' => CrewAssignmentPresenter::detail($assignment, $request->user()),
            'form_options' => $formOptions,
            'can' => CrewAssignmentPagePermissions::for($request->user()),
        ]);
    }

    public function update(UpdateCrewAssignmentRequest $request, CrewAssignment $assignment)
    {
        Gate::authorize('update', $assignment);

        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $validated = $request->validated();

        if (! CrewAssignmentEditability::isEditable($assignment)) {
            throw ValidationException::withMessages([
                'error' => 'Only draft assignments or those before P4 can be updated.',
            ]);
        }

        $updateData = Arr::only($validated, [
            'rank_id',
            'client_id',
            'vessel_id',
            'planned_join_at',
            'planned_arrival_at',
            'remarks',
        ]);
        $updateData['updated_by'] = $request->user()?->id;

        DB::transaction(function () use ($assignment, $updateData): void {
            $assignment->update($updateData);
            $this->planningSync->sync($assignment->fresh(['phases', 'employee', 'company']) ?? $assignment);
        });

        return redirect()
            ->route('organization.crew-assignments.show', $assignment)
            ->with('success', 'Crew assignment updated successfully.');
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
            ->map(fn (Rank $rank) => ['id' => $rank->id, 'name' => $rank->name])
            ->values()
            ->all();
    }

    /**
     * Rank options enriched with Tour of Duty resolution for Join Vessel.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     max_tour_of_duty_days: int|null,
     *     resolved_tour_of_duty_days: int|null
     * }>
     */
    private function activeRanksWithTour(int $companyId): array
    {
        return Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'max_tour_of_duty_days'])
            ->map(fn (Rank $rank): array => [
                'id' => $rank->id,
                'name' => $rank->name,
                'max_tour_of_duty_days' => $rank->max_tour_of_duty_days !== null ? (int) $rank->max_tour_of_duty_days : null,
                'resolved_tour_of_duty_days' => $rank->max_tour_of_duty_days !== null ? (int) $rank->max_tour_of_duty_days : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, client_id: int|null, is_active: bool}>
     */
    private function activeVessels(int $companyId): array
    {
        return array_map(
            static fn (array $option): array => [...$option, 'is_active' => true],
            ResolvesCompanyVessels::activeOptions($companyId, requireAssignedClient: true),
        );
    }

    /**
     * Operational vessel options plus the assignment's existing Vessel when it is
     * missing from the selectable list (legacy unassigned / inactive continuity).
     *
     * @return list<array{id: int, name: string, client_id: int|null, is_active: bool}>
     */
    private function vesselOptionsForAssignment(int $companyId, CrewAssignment $assignment): array
    {
        $options = $this->activeVessels($companyId);
        $existingVesselId = $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null;

        if ($existingVesselId === null) {
            return $options;
        }

        foreach ($options as $option) {
            if ((int) $option['id'] === $existingVesselId) {
                return $options;
            }
        }

        $existing = ResolvesCompanyVessels::queryForCompany($companyId)
            ->whereKey($existingVesselId)
            ->first(['id', 'name', 'client_id', 'is_active']);

        if ($existing === null) {
            return $options;
        }

        $label = (string) $existing->name;
        $isActive = (bool) $existing->is_active;

        if ($existing->client_id === null) {
            $label .= ' — Legacy / Client not assigned';
        } elseif (! $isActive) {
            $label .= ' — Inactive';
        }

        $options[] = [
            'id' => (int) $existing->id,
            'name' => $label,
            'client_id' => $existing->client_id !== null ? (int) $existing->client_id : null,
            'is_active' => $isActive,
        ];

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeClients(): array
    {
        return Client::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
            ->values()
            ->all();
    }

    /**
     * Active Client options plus the assignment's existing Client when inactive
     * (historical snapshot continuity for unrelated edits).
     *
     * @return list<array{id: int, name: string}>
     */
    private function clientOptionsForAssignment(CrewAssignment $assignment): array
    {
        $options = $this->activeClients();
        $existingClientId = $assignment->client_id !== null ? (int) $assignment->client_id : null;

        if ($existingClientId === null) {
            return $options;
        }

        foreach ($options as $option) {
            if ((int) $option['id'] === $existingClientId) {
                return $options;
            }
        }

        $existing = Client::query()->find($existingClientId, ['id', 'name', 'is_active']);

        if ($existing === null) {
            return $options;
        }

        $label = (string) $existing->name;

        if (! (bool) $existing->is_active) {
            $label .= ' — Inactive';
        }

        $options[] = [
            'id' => (int) $existing->id,
            'name' => $label,
        ];

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeCourses(): array
    {
        return Course::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Course $course) => ['id' => $course->id, 'name' => $course->name])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     employees: list<array<string, mixed>>,
     *     ranks: list<array<string, mixed>>,
     *     vessels: list<array<string, mixed>>,
     *     clients: list<array<string, mixed>>,
     *     courses: list<array<string, mixed>>,
     *     hotels: list<array{id: int, name: string}>,
     *     room_types: list<array{id: int, name: string}>
     * }
     */
    private function movementFormOptions(int $companyId): array
    {
        return [
            'employees' => [],
            'ranks' => $this->activeRanksWithTour($companyId),
            'vessels' => $this->activeVessels($companyId),
            'clients' => $this->activeClients(),
            'courses' => $this->activeCourses(),
            'hotels' => $this->activeHotels($companyId),
            'room_types' => $this->activeRoomTypes($companyId),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeHotels(int $companyId): array
    {
        return Hotel::query()
            ->forCompany($companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Hotel $hotel) => ['id' => $hotel->id, 'name' => $hotel->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeRoomTypes(int $companyId): array
    {
        return RoomType::query()
            ->forCompany($companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (RoomType $roomType) => ['id' => $roomType->id, 'name' => $roomType->name])
            ->values()
            ->all();
    }
}
