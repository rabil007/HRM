<?php

namespace App\Http\Controllers\Organization;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreCrewAssignmentRequest;
use App\Http\Requests\Organization\UpdateCrewAssignmentRequest;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\Activity\RecentActivityQuery;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentPagePermissions;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CrewAssignmentController extends Controller
{
    use ResolvesPerPage;

    public function __construct(private CrewMovementService $service) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');

        $filters = [
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
            'movement_attention' => filter_var($request->query('movement_attention', false), FILTER_VALIDATE_BOOLEAN),
            'include_completed' => filter_var($request->query('include_completed', false), FILTER_VALIDATE_BOOLEAN),
            'sort' => $request->query('sort', 'created_at'),
            'direction' => $request->query('direction', 'desc'),
            'per_page' => $request->query('per_page'),
        ];

        $paginator = CurrentCrewQuery::paginate($companyId, $filters);

        $assignments = $paginator->through(fn (CrewAssignment $assignment) => CrewAssignmentPresenter::listItem($assignment));

        $summary = CrewMovementAttentionQuery::summaryCounts($companyId);
        $filterOptions = CurrentCrewQuery::filterOptions($companyId);

        return Inertia::render('organization/crew/index', [
            'assignments' => $assignments->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $filters['search'],
            'filters' => array_filter($filters, fn ($v) => $v !== '' && $v !== null && $v !== false),
            'summary' => $summary,
            'filter_options' => $filterOptions,
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
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'employee_number', 'rank_id'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'employee_number' => $e->employee_number,
                    'rank_id' => $e->rank_id,
                ])
                ->values()
                ->all(),
            'ranks' => Rank::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Rank $r) => ['id' => $r->id, 'name' => $r->name])
                ->values()
                ->all(),
            'vessels' => Vessel::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Vessel $v) => ['id' => $v->id, 'name' => $v->name])
                ->values()
                ->all(),
            'clients' => Client::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'visa_types' => CompanyVisaType::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CompanyVisaType $vt) => ['id' => $vt->id, 'name' => $vt->name])
                ->values()
                ->all(),
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

            return redirect()
                ->route('organization.crew-assignments.show', $assignment)
                ->with('success', 'Crew assignment created successfully.');
        } catch (CrewMovementException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }
    }

    public function show(Request $request, CrewAssignment $assignment)
    {
        Gate::authorize('view', $assignment);

        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $assignment->load([
            'employee',
            'rank',
            'client',
            'vessel',
            'companyVisaType',
            'currentPhase',
            'phases',
            'planningAssignment',
        ]);

        $detail = CrewAssignmentPresenter::detail($assignment);

        $recentActivity = Gate::allows('viewAudit', CrewAssignment::class)
            ? RecentActivityQuery::for($request->user(), $companyId, CrewAssignment::class, $assignment->id)
            : [];

        return Inertia::render('organization/crew/show', [
            'assignment' => $detail,
            'recent_activity' => $recentActivity,
            'can' => CrewAssignmentPagePermissions::for($request->user()),
        ]);
    }

    public function edit(Request $request, CrewAssignment $assignment)
    {
        Gate::authorize('update', $assignment);

        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

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
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'employee_number', 'rank_id'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'employee_number' => $e->employee_number,
                    'rank_id' => $e->rank_id,
                ])
                ->values()
                ->all(),
            'ranks' => Rank::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Rank $r) => ['id' => $r->id, 'name' => $r->name])
                ->values()
                ->all(),
            'vessels' => Vessel::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Vessel $v) => ['id' => $v->id, 'name' => $v->name])
                ->values()
                ->all(),
            'clients' => Client::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'visa_types' => CompanyVisaType::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CompanyVisaType $vt) => ['id' => $vt->id, 'name' => $vt->name])
                ->values()
                ->all(),
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

        $current = $assignment->currentPhase;
        $canUpdateAll = $assignment->status === CrewAssignmentStatus::Draft
            || ($current && $current->phase_code->value < CrewPhaseCode::OnVessel->value);

        if (! $canUpdateAll) {
            throw ValidationException::withMessages([
                'error' => 'Only draft assignments or those before P4 can be updated.',
            ]);
        }

        $updateData = array_filter([
            'rank_id' => $validated['rank_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'vessel_id' => $validated['vessel_id'] ?? null,
            'company_visa_type_id' => $validated['company_visa_type_id'] ?? null,
            'planned_join_at' => $validated['planned_join_at'] ?? null,
            'planned_signoff_at' => $validated['planned_signoff_at'] ?? null,
            'planned_travel_at' => $validated['planned_travel_at'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'updated_by' => $request->user()?->id,
        ], fn ($v) => $v !== null);

        $assignment->update($updateData);

        return redirect()
            ->route('organization.crew-assignments.show', $assignment)
            ->with('success', 'Crew assignment updated successfully.');
    }
}
