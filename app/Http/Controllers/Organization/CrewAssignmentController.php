<?php

namespace App\Http\Controllers\Organization;

use App\Enums\RecentItemType;
use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreCrewAssignmentRequest;
use App\Http\Requests\Organization\UpdateCrewAssignmentRequest;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Support\Activity\RecentActivityQuery;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPresenter;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentEditability;
use App\Support\CrewMovements\CrewAssignmentPagePermissions;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\CrewMovements\CurrentCrewVesselQuery;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\RecentItems\RecordRecentItem;
use App\Support\Vessels\ResolvesCompanyVessels;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CrewAssignmentController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private CrewMovementService $service,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = CurrentCrewRequestFilters::fromRequest($request);
        $view = CurrentCrewRequestFilters::view($request);

        if ($view === CurrentCrewRequestFilters::VIEW_VESSEL) {
            $vesselPaginator = CurrentCrewVesselQuery::paginate($companyId, $filters);
            $assignments = [];
            $vessels = $vesselPaginator->items();
            $pagination = $this->paginationMeta($vesselPaginator);
        } else {
            $paginator = CurrentCrewQuery::paginate($companyId, $filters);
            $assignments = $paginator->through(fn (CrewAssignment $assignment) => CrewAssignmentPresenter::listItem($assignment))->items();
            $vessels = [];
            $pagination = $this->paginationMeta($paginator);
        }

        $summary = CrewMovementAttentionQuery::summaryCounts($companyId);
        $filterOptions = CurrentCrewQuery::filterOptions($companyId);

        return Inertia::render('organization/crew/index', [
            'view' => $view,
            'assignments' => $assignments,
            'vessels' => $vessels,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'filters' => CurrentCrewRequestFilters::inertiaFilters($filters),
            'summary' => $summary,
            'filter_options' => $filterOptions,
            'form_options' => [
                'employees' => [],
                'ranks' => $this->activeRanksWithTour($companyId),
                'vessels' => $this->activeVessels($companyId),
                'clients' => $this->activeClients(),
                'visa_types' => $this->activeVisaTypes(),
                'courses' => $this->activeCourses(),
            ],
            'can' => CrewAssignmentPagePermissions::for($request->user()),
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');

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
            'vessels' => $this->activeVessels($companyId),
            'clients' => $this->activeClients(),
            'visa_types' => $this->activeVisaTypes(),
            'courses' => $this->activeCourses(),
        ];

        return Inertia::render('organization/crew/create', [
            'form_options' => $formOptions,
            'can' => CrewAssignmentPagePermissions::for($request->user()),
        ]);
    }

    public function store(StoreCrewAssignmentRequest $request)
    {
        Gate::authorize('create', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        try {
            $assignment = DB::transaction(function () use ($companyId, $validated, $request) {
                $assignment = $this->service->createDraft(
                    $companyId,
                    (int) $validated['employee_id'],
                    [
                        'rank_id' => $validated['rank_id'] ?? null,
                        'client_id' => $validated['client_id'] ?? null,
                        'vessel_id' => $validated['vessel_id'] ?? null,
                        'company_visa_type_id' => $validated['company_visa_type_id'] ?? null,
                        'planned_join_at' => $validated['planned_join_at'] ?? null,
                        'planned_signoff_at' => $validated['planned_signoff_at'] ?? null,
                        'planned_travel_at' => $validated['planned_travel_at'] ?? null,
                        'remarks' => $validated['remarks'] ?? null,
                    ],
                    $request->user()?->id,
                );

                $this->planningSync->sync($assignment);

                return $assignment->fresh() ?? $assignment;
            });

            return redirect()
                ->route('organization.crew-assignments.show', $assignment)
                ->with('success', 'Crew assignment created successfully.');
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
            'companyVisaType',
            'currentPhase',
            'phases.pendingCorrections',
            'phases.corrections' => fn ($query) => $query->where('status', 'approved')->latest('decided_at'),
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
        ]);

        $detail = CrewAssignmentPresenter::detail($assignment);
        $corrections = app(CrewMovementCorrectionPresenter::class)->assignmentSummary($assignment);

        $recentActivity = Gate::allows('viewAudit', CrewAssignment::class)
            ? RecentActivityQuery::for($request->user(), $companyId, CrewAssignment::class, $assignment->id)
            : [];

        return Inertia::render('organization/crew/show', [
            'assignment' => $detail,
            'corrections' => $corrections,
            'recent_activity' => $recentActivity,
            'form_options' => [
                'employees' => [],
                'ranks' => $this->activeRanksWithTour($companyId),
                'vessels' => $this->activeVessels($companyId),
                'clients' => $this->activeClients(),
                'visa_types' => $this->activeVisaTypes(),
                'courses' => $this->activeCourses(),
            ],
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
            'companyVisaType',
            'currentPhase',
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
            'vessels' => $this->activeVessels($companyId),
            'clients' => $this->activeClients(),
            'visa_types' => $this->activeVisaTypes(),
            'courses' => $this->activeCourses(),
        ];

        return Inertia::render('organization/crew/edit', [
            'assignment' => CrewAssignmentPresenter::detail($assignment),
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
            'company_visa_type_id',
            'planned_join_at',
            'planned_signoff_at',
            'planned_travel_at',
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
     * @return list<array{id: int, name: string}>
     */
    private function activeVessels(int $companyId): array
    {
        return ResolvesCompanyVessels::activeOptions($companyId);
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
     * @return list<array{id: int, name: string}>
     */
    private function activeVisaTypes(): array
    {
        return CompanyVisaType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CompanyVisaType $visaType) => ['id' => $visaType->id, 'name' => $visaType->name])
            ->values()
            ->all();
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
}
