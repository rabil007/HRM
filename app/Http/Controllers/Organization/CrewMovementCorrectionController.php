<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreCrewMovementCorrectionRequest;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionAccess;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionIndexQuery;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPagePermissions;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPresenter;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewMovementCorrectionController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private readonly RequestCrewMovementCorrection $requestCorrection,
        private readonly CrewMovementCorrectionPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $query = CrewMovementCorrectionIndexQuery::fromRequest($request, $companyId);
        $paginator = $query->paginate($perPage);

        return Inertia::render('organization/crew-movement-corrections/index', [
            'corrections' => collect($paginator->items())
                ->map(fn (CrewMovementCorrection $correction) => $this->presenter->listItem($correction))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'status_counts' => $query->statusCounts(),
            'summary_counts' => $query->summaryCounts(),
            'search' => trim((string) $request->query('search', '')),
            'filters' => [
                'status' => trim((string) $request->query('status', '')),
                'scope' => trim((string) $request->query('scope', '')),
                'sla_status' => trim((string) $request->query('sla_status', '')),
            ],
            'can' => CrewMovementCorrectionPagePermissions::for($request->user()),
        ]);
    }

    public function show(Request $request, CrewMovementCorrection $correction): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewMovementCorrectionAccess::assertInCompany($correction, $companyId);

        $correction->load([
            'company:id,timezone',
            'assignment.employee:id,company_id,employee_no,name',
            'assignment.vessel:id,name',
            'assignment.rank:id,name',
            'assignment.client:id,name',
            'assignment.companyVisaType:id,name',
            'phase',
            'requester:id,name',
            'decisionMaker:id,name',
        ]);

        return Inertia::render('organization/crew-movement-corrections/show', [
            'correction' => $this->presenter->detail($correction, $request->user()),
            'can' => CrewMovementCorrectionPagePermissions::for($request->user()),
        ]);
    }

    public function store(
        StoreCrewMovementCorrectionRequest $request,
        CrewAssignment $assignment,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $phase = CrewAssignmentPhase::query()
            ->whereKey((int) $request->validated('crew_assignment_phase_id'))
            ->where('crew_assignment_id', $assignment->id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        try {
            $correction = $this->requestCorrection->handle(
                $assignment,
                $phase,
                $request->user(),
                $request->validated('proposed_values'),
                (string) $request->validated('reason'),
            );
        } catch (CrewMovementException $exception) {
            return back()->withErrors([
                'correction' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.crew-movement-corrections.show', $correction)
            ->with('success', 'Correction request submitted.');
    }
}
