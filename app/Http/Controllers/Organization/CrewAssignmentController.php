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
use App\Models\User;
use App\Models\Vessel;
use App\Support\Activity\RecentActivityQuery;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPresenter;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentCreateFormOptions;
use App\Support\CrewMovements\CrewAssignmentEditability;
use App\Support\CrewMovements\CrewAssignmentPagePermissions;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CrewReliefVisibility;
use App\Support\CrewMovements\CurrentCrewHomePresenter;
use App\Support\CrewMovements\CurrentCrewHomeQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use App\Support\CrewPlanning\CrewPlanningAssignmentAccess;
use App\Support\CrewPlanning\LinkVacantCrewPlanningSlot;
use App\Support\CrewPlanning\ResolvePlanningStartHandoff;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\RecentItems\RecordRecentItem;
use App\Support\SavedViews\ApplyDefaultSavedView;
use App\Support\SavedViews\SavedViewsForPage;
use App\Support\Settings\CompanyTimezone;
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
            $vesselPaginator = CurrentCrewVesselQuery::paginate($companyId, $filters, $request->user());
            $assignments = [];
            $homeCrew = [];
            $vessels = $vesselPaginator->items();
            $pagination = $this->paginationMeta($vesselPaginator);
        } elseif ($view === CurrentCrewRequestFilters::VIEW_ON_HOME) {
            $filters['page'] = max(1, (int) $request->query('page', 1));
            $homePaginator = CurrentCrewHomeQuery::paginate($companyId, $filters, $request->user());
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
            $paginator = CurrentCrewQuery::paginate($companyId, $filters, $view, $request->user());
            $items = $paginator->items();
            $authorizedReliefEmployeeIds = CrewReliefVisibility::authorizedReliefEmployeeIds(
                $items,
                $request->user(),
                $companyId,
            );
            $assignments = collect($items)
                ->map(fn (CrewAssignment $assignment): array => CrewAssignmentPresenter::listItem(
                    $assignment,
                    $request->user(),
                    $authorizedReliefEmployeeIds,
                ))
                ->all();
            $homeCrew = [];
            $vessels = [];
            $pagination = $this->paginationMeta($paginator);
        }

        $summary = CrewMovementAttentionQuery::summaryCounts($companyId, $request->user());
        $filterOptions = CurrentCrewQuery::filterOptions($companyId, $request->user());
        $can = CrewAssignmentPagePermissions::for($request->user());

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
            'historical_form_options' => ($can['create_historical'] ?? false) && $request->user() !== null
                ? $this->historicalFormOptions($companyId, $request->user())
                : null,
            'can' => $can,
            'saved_views' => SavedViewsForPage::props($request->user(), $companyId, SavedViewPage::Crew),
        ]);
    }

    public function create(Request $request, ResolvePlanningStartHandoff $planningHandoff)
    {
        $isPlanIntent = $request->query('intent') === 'plan';

        if ($isPlanIntent) {
            Gate::authorize('plan', CrewAssignment::class);
        } else {
            Gate::authorize('create', CrewAssignment::class);
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $permissions = CrewAssignmentPagePermissions::for($request->user());
        $initialRowCount = $request->query('mode') === 'bulk' && $permissions['start'] ? 2 : 1;
        $planningContext = null;
        $planningBackQuery = [];

        $planningAssignmentId = $request->query('planning_assignment_id');

        if ($planningAssignmentId !== null && $planningAssignmentId !== '') {
            $canOpenHandoff = $request->user()?->can('crew_operations.planning.view')
                && ($permissions['start'] || ($isPlanIntent && $permissions['plan']));

            if (! $canOpenHandoff) {
                abort(403);
            }

            $planning = CrewPlanningAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $planningAssignmentId)
                ->firstOrFail();

            CrewPlanningAssignmentAccess::assertInCompany(
                $planning,
                $companyId,
                $request->user(),
            );

            $linked = $planningHandoff->linkedAssignment($planning);

            if ($linked !== null) {
                $visibleLinked = CrewAssignmentAccess::findForCompany(
                    $companyId,
                    (int) $linked->id,
                    $request->user(),
                );

                if ($visibleLinked !== null) {
                    return redirect()
                        ->route('organization.crew-assignments.show', $visibleLinked)
                        ->with('success', $planningHandoff->redirectMessageForLinked($visibleLinked));
                }

                abort(404);
            }

            try {
                $planningContext = $planning->employee_id === null
                    ? $planningHandoff->vacantPrefill($planning, $companyId)
                    : $planningHandoff->prefill($planning, $companyId, $request->user());
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

        $intent = $request->query('intent') === 'plan' ? 'plan' : ($request->query('intent') === 'start' ? 'start' : null);
        $prefill = array_filter([
            'employee_id' => $request->query('employee_id') ? (int) $request->query('employee_id') : null,
            'vessel_id' => $request->query('vessel_id') ? (int) $request->query('vessel_id') : null,
            'rank_id' => $request->query('rank_id') ? (int) $request->query('rank_id') : null,
            'client_id' => $request->query('client_id') ? (int) $request->query('client_id') : null,
            'planned_join_at' => $request->query('planned_join_at') ?? $request->query('from'),
            'planned_signoff_at' => $request->query('planned_signoff_at') ?? $request->query('to'),
            'relieves_crew_assignment_id' => $request->query('relieves_crew_assignment_id') ? (int) $request->query('relieves_crew_assignment_id') : null,
        ], fn ($value) => $value !== null && $value !== '');

        return Inertia::render('organization/crew/create', [
            'form_options' => CrewAssignmentCreateFormOptions::for($companyId, $request->user()),
            'can' => CrewAssignmentPagePermissions::for($request->user()),
            'initial_row_count' => $initialRowCount,
            'intent' => $intent,
            'prefill' => $prefill !== [] ? $prefill : null,
            'planning_context' => $planningContext,
            'planning_back_query' => $planningBackQuery !== [] ? $planningBackQuery : null,
        ]);
    }

    public function store(StoreCrewAssignmentRequest $request, LinkVacantCrewPlanningSlot $linkVacantSlot)
    {
        $intent = $request->submissionIntent();

        if ($intent === CrewAssignmentSubmissionIntent::Start) {
            Gate::authorize('start', CrewAssignment::class);
        } elseif ($intent === CrewAssignmentSubmissionIntent::Plan) {
            Gate::authorize('plan', CrewAssignment::class);
        } else {
            Gate::authorize('create', CrewAssignment::class);
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();
        $planningAssignmentId = $validated['planning_assignment_id'] ?? null;

        try {
            $assignment = DB::transaction(function () use ($intent, $companyId, $validated, $request, $planningAssignmentId, $linkVacantSlot) {
                $created = match ($intent) {
                    CrewAssignmentSubmissionIntent::Start => $this->service->startAssignment(
                        $companyId,
                        (int) $validated['employee_id'],
                        [
                            'rank_id' => $validated['rank_id'] ?? null,
                            'client_id' => $validated['client_id'] ?? null,
                            'vessel_id' => $validated['vessel_id'] ?? null,
                            'planned_join_at' => $validated['planned_join_at'] ?? null,
                            'planned_arrival_at' => $validated['planned_arrival_at'] ?? null,
                            'planned_signoff_at' => $validated['planned_signoff_at'] ?? null,
                            'relieves_crew_assignment_id' => $validated['relieves_crew_assignment_id'] ?? null,
                            'current_stage' => CrewPhaseCode::PreMobilisation->value,
                            'remarks' => $validated['remarks'] ?? null,
                        ],
                        $request->user()?->id,
                    ),
                    CrewAssignmentSubmissionIntent::Plan => $this->service->createPlanned(
                        $companyId,
                        (int) $validated['employee_id'],
                        [
                            'rank_id' => $validated['rank_id'] ?? null,
                            'client_id' => $validated['client_id'] ?? null,
                            'vessel_id' => $validated['vessel_id'] ?? null,
                            'planned_join_at' => $validated['planned_join_at'] ?? null,
                            'planned_arrival_at' => $validated['planned_arrival_at'] ?? null,
                            'planned_signoff_at' => $validated['planned_signoff_at'] ?? null,
                            'relieves_crew_assignment_id' => $validated['relieves_crew_assignment_id'] ?? null,
                            'remarks' => $validated['remarks'] ?? null,
                        ],
                        $request->user()?->id,
                    ),
                    CrewAssignmentSubmissionIntent::Draft => $this->service->createDraft(
                        $companyId,
                        (int) $validated['employee_id'],
                        [
                            'rank_id' => $validated['rank_id'] ?? null,
                            'client_id' => $validated['client_id'] ?? null,
                            'vessel_id' => $validated['vessel_id'] ?? null,
                            'planned_join_at' => $validated['planned_join_at'] ?? null,
                            'planned_arrival_at' => $validated['planned_arrival_at'] ?? null,
                            'planned_signoff_at' => $validated['planned_signoff_at'] ?? null,
                            'relieves_crew_assignment_id' => $validated['relieves_crew_assignment_id'] ?? null,
                            'remarks' => $validated['remarks'] ?? null,
                        ],
                        $request->user()?->id,
                    ),
                };

                if ($planningAssignmentId !== null) {
                    $linkVacantSlot->handle(
                        $companyId,
                        (int) $planningAssignmentId,
                        $created,
                        [
                            'vessel_id' => $validated['vessel_id'] ?? null,
                            'rank_id' => $validated['rank_id'] ?? null,
                            'planned_join_at' => $validated['planned_join_at'] ?? null,
                            'planned_signoff_at' => $validated['planned_signoff_at'] ?? null,
                        ],
                        $request->user(),
                    );
                }

                return $created->fresh() ?? $created;
            });

            $success = match ($intent) {
                CrewAssignmentSubmissionIntent::Start => 'Crew assignment started successfully.',
                CrewAssignmentSubmissionIntent::Plan => 'Crew assignment saved as planned.',
                CrewAssignmentSubmissionIntent::Draft => 'Crew assignment created successfully.',
            };

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
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        $user = $request->user();
        if ($user !== null) {
            $recordRecentItem->handle($user, $companyId, RecentItemType::CrewAssignment, $assignment->id);
        }

        $canViewCorrections = $user?->can('crew_operations.corrections.view') ?? false;
        $canRequestCorrection = $user?->can('crew_operations.corrections.request') ?? false;
        $canOverrideCorrections = $user?->can('crew_operations.corrections.override') ?? false;
        $needsCorrectionActionContext = $canRequestCorrection || $canOverrideCorrections;

        $eagerLoads = [
            'company:id,timezone',
            'employee',
            'rank',
            'client',
            'vessel',
            'currentPhase',
            'accommodationStays.hotel',
            'accommodationStays.roomType',
            'phases.employeeTraining:id,source_crew_assignment_phase_id',
            'planningAssignment.relievedAssignment.employee',
            'planningAssignment.relievedAssignment.vessel',
            'planningAssignment.relievedAssignment.rank',
            'previousAssignment:id,assignment_no,status,vessel_id,source,closed_at',
            'previousAssignment.vessel:id,name',
            'nextAssignments:id,assignment_no,status,vessel_id,source,previous_assignment_id,started_at',
            'nextAssignments.vessel:id,name',
        ];

        if ($canViewCorrections) {
            $eagerLoads[] = 'phases.pendingCorrections';
            $eagerLoads['phases.corrections'] = fn ($query) => $query->where('status', 'approved')->latest('decided_at');
            $eagerLoads[] = 'corrections.requester:id,name';
            $eagerLoads[] = 'corrections.decisionMaker:id,name';
            $eagerLoads[] = 'corrections.phase';
            $eagerLoads[] = 'corrections.company:id,timezone';
        } elseif ($needsCorrectionActionContext) {
            $eagerLoads[] = 'phases.pendingCorrections';
        }

        $assignment->load($eagerLoads);

        $detail = CrewAssignmentPresenter::detail($assignment, $request->user());
        $correctionPresenter = app(CrewMovementCorrectionPresenter::class);

        $corrections = $canViewCorrections
            ? $correctionPresenter->assignmentSummary($assignment)
            : null;

        $correctionRequestContext = $needsCorrectionActionContext
            ? $correctionPresenter->correctionRequestContext($assignment)
            : null;

        $recentActivity = Gate::allows('viewAudit', CrewAssignment::class)
            ? RecentActivityQuery::for($request->user(), $companyId, CrewAssignment::class, $assignment->id)
            : [];

        return Inertia::render('organization/crew/show', [
            'assignment' => $detail,
            'corrections' => $corrections,
            'correction_request_context' => $correctionRequestContext,
            'recent_activity' => $recentActivity,
            'form_options' => $this->movementFormOptions($companyId),
            'can' => CrewAssignmentPagePermissions::for($request->user()),
        ]);
    }

    public function edit(Request $request, CrewAssignment $assignment)
    {
        Gate::authorize('update', $assignment);

        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        if (! CrewAssignmentEditability::isEditable($assignment)) {
            $actionMessage = $request->user()?->can('crew_operations.corrections.override')
                ? 'This assignment can no longer be edited directly. Use Movement Actions or Correct Movement.'
                : 'This assignment can no longer be edited directly. Use Movement Actions or Request Correction.';

            return redirect()
                ->route('organization.crew-assignments.show', $assignment)
                ->with('error', $actionMessage);
        }

        $assignment->load([
            'employee',
            'rank',
            'client',
            'vessel',
            'currentPhase',
            'phases.employeeTraining:id,source_crew_assignment_phase_id',
        ]);

        $employeeId = (int) $assignment->employee_id;
        $operationalContext = CrewAssignmentCreateFormOptions::operationalContextForEmployees(
            $companyId,
            $request->user(),
            [$employeeId],
        );

        $employeeQuery = Employee::query()
            ->where('company_id', $companyId)
            ->active();

        $employeeQuery = EmployeeVisibilityScope::apply($employeeQuery, $request->user(), $companyId);

        $formOptions = [
            'employees' => $employeeQuery
                ->with(['nationalityRef:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'employee_no', 'rank_id', 'image', 'nationality_id'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'employee_no' => $e->employee_no,
                    'rank_id' => $e->rank_id,
                    'image' => $e->image,
                    'nationality_name' => $e->nationalityRef?->name,
                ])
                ->values()
                ->all(),
            'ranks' => $this->activeRanks(),
            'vessels' => $this->vesselOptionsForAssignment($companyId, $assignment),
            'clients' => $this->clientOptionsForAssignment($assignment),
            'courses' => $this->activeCourses(),
            'company_timezone' => CompanyTimezone::forCompanyId($companyId),
            ...$operationalContext,
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
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

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
            'planned_signoff_at',
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
     *     room_types: list<array{id: int, name: string, hotel_id: int|null}>,
     *     company_timezone: string
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
            'company_timezone' => CompanyTimezone::forCompanyId($companyId),
        ];
    }

    /**
     * @return array{
     *     employees: list<array{id: int, name: string, employee_no: string|null, rank_id: int|null, status: string}>,
     *     ranks: list<array<string, mixed>>,
     *     vessels: list<array<string, mixed>>,
     *     clients: list<array<string, mixed>>,
     *     company_timezone: string
     * }
     */
    private function historicalFormOptions(int $companyId, User $user): array
    {
        $employeeQuery = Employee::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');

        $employeeQuery = EmployeeVisibilityScope::apply($employeeQuery, $user, $companyId);

        $employees = $employeeQuery
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no', 'rank_id', 'status'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'rank_id' => $employee->rank_id,
                'status' => $employee->status,
            ])
            ->values()
            ->all();

        return [
            'employees' => $employees,
            'ranks' => $this->historicalRanksWithTour(),
            'vessels' => $this->historicalVessels($companyId),
            'clients' => $this->historicalClients(),
            'company_timezone' => CompanyTimezone::forCompanyId($companyId),
        ];
    }

    /**
     * Historical backfill may reference inactive ranks. Live form options stay active-only.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     is_active: bool,
     *     max_tour_of_duty_days: int|null,
     *     resolved_tour_of_duty_days: int|null
     * }>
     */
    private function historicalRanksWithTour(): array
    {
        return Rank::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'max_tour_of_duty_days'])
            ->map(function (Rank $rank): array {
                $isActive = (bool) $rank->is_active;
                $label = (string) $rank->name;

                if (! $isActive) {
                    $label .= ' — Inactive';
                }

                return [
                    'id' => (int) $rank->id,
                    'name' => $label,
                    'is_active' => $isActive,
                    'max_tour_of_duty_days' => $rank->max_tour_of_duty_days !== null ? (int) $rank->max_tour_of_duty_days : null,
                    'resolved_tour_of_duty_days' => $rank->max_tour_of_duty_days !== null ? (int) $rank->max_tour_of_duty_days : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Historical backfill may reference inactive company vessels (including legacy unassigned clients).
     *
     * @return list<array{id: int, name: string, client_id: int|null, is_active: bool}>
     */
    private function historicalVessels(int $companyId): array
    {
        return ResolvesCompanyVessels::queryForCompany($companyId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'client_id', 'is_active'])
            ->map(function (Vessel $vessel): array {
                $isActive = (bool) $vessel->is_active;
                $label = (string) $vessel->name;

                if (! $isActive) {
                    $label .= ' — Inactive';
                }

                return [
                    'id' => (int) $vessel->id,
                    'name' => $label,
                    'client_id' => $vessel->client_id !== null ? (int) $vessel->client_id : null,
                    'is_active' => $isActive,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Historical backfill may reference inactive clients.
     *
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    private function historicalClients(): array
    {
        return Client::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(function (Client $client): array {
                $isActive = (bool) $client->is_active;
                $label = (string) $client->name;

                if (! $isActive) {
                    $label .= ' — Inactive';
                }

                return [
                    'id' => (int) $client->id,
                    'name' => $label,
                    'is_active' => $isActive,
                ];
            })
            ->values()
            ->all();
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
     * @return list<array{id: int, name: string, hotel_id: int|null}>
     */
    private function activeRoomTypes(int $companyId): array
    {
        return RoomType::query()
            ->forCompany($companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'hotel_id'])
            ->map(fn (RoomType $roomType) => [
                'id' => $roomType->id,
                'name' => $roomType->name,
                'hotel_id' => $roomType->hotel_id !== null ? (int) $roomType->hotel_id : null,
            ])
            ->values()
            ->all();
    }
}
