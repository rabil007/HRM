<?php

namespace App\Http\Controllers\Organization;

use App\Exports\CrewDeploymentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewDeployment\StoreEmployeeDeploymentRequest;
use App\Http\Requests\Organization\CrewDeployment\UpdateEmployeeDeploymentRequest;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\Activity\RecentActivityQuery;
use App\Support\CrewDeployments\CrewDeploymentBoardQuery;
use App\Support\CrewDeployments\CrewDeploymentBoardSort;
use App\Support\CrewDeployments\CrewDeploymentPagePermissions;
use App\Support\CrewDeployments\DeploymentStatusRules;
use App\Support\CrewDeployments\EmployeeDeploymentPresenter;
use App\Support\CrewDeployments\SyncSeaServiceFromDeployment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CrewDeploymentController extends Controller
{
    public function index(Request $request, CrewDeploymentBoardQuery $boardQuery): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $status = $request->string('status')->toString() ?: null;
        $search = $request->string('search')->toString() ?: null;

        [$sort, $direction] = CrewDeploymentBoardSort::normalize(
            $request->string('sort')->toString() ?: null,
            $request->string('direction')->toString() ?: null,
        );

        $result = $boardQuery->paginate(
            companyId: $companyId,
            status: $status,
            search: $search,
            rankId: $request->integer('rank_id') ?: null,
            clientId: $request->integer('client_id') ?: null,
            companyVisaTypeId: $request->integer('company_visa_type_id') ?: null,
            sort: $sort,
            direction: $direction,
            perPage: min(max($request->integer('per_page', 25), 10), 100),
        );

        $paginator = $result['paginator'];

        $view = $request->string('view')->toString() ?: null;

        return Inertia::render('organization/crew-deployments/index', [
            'deployments' => [
                'data' => $paginator->items(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'summary' => $result['summary'],
            'filters' => [
                'status' => $status,
                'search' => $search,
                'rank_id' => $request->integer('rank_id') ?: null,
                'client_id' => $request->integer('client_id') ?: null,
                'company_visa_type_id' => $request->integer('company_visa_type_id') ?: null,
                'sort' => $sort,
                'direction' => $direction,
                'view' => $view,
            ],
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'employee_no', 'name', 'rank_id']),
            'ranks' => Rank::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'company_visa_types' => CompanyVisaType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vessels' => Vessel::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => CrewDeploymentPagePermissions::for($request->user()),
            'status_rules' => DeploymentStatusRules::forPage(),
        ]);
    }

    public function store(
        StoreEmployeeDeploymentRequest $request,
        SyncSeaServiceFromDeployment $syncSeaService,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->where('company_id', $companyId)
            ->firstOrFail();

        $maxSort = EmployeeDeployment::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        $deployment = EmployeeDeployment::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            'rank_id' => $validated['rank_id'] ?? $employee->rank_id,
            ...$this->deploymentAttributes($validated),
        ]);

        $syncSeaService->sync($deployment);

        return back()->with('success', 'Deployment record added.');
    }

    public function show(Request $request, EmployeeDeployment $deployment): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($deployment->company_id === $companyId, 404);

        return Inertia::render('organization/crew-deployments/show', [
            'deployment' => EmployeeDeploymentPresenter::toArray($deployment),
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                EmployeeDeployment::class,
                $deployment->id,
                limit: 20,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
            'can' => CrewDeploymentPagePermissions::for($request->user()),
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'employee_no', 'name', 'rank_id']),
            'ranks' => Rank::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'company_visa_types' => CompanyVisaType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vessels' => Vessel::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'back_query' => $this->listBackQuery($request),
        ]);
    }

    public function update(
        UpdateEmployeeDeploymentRequest $request,
        EmployeeDeployment $deployment,
        SyncSeaServiceFromDeployment $syncSeaService,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($deployment->company_id === $companyId, 403);

        $validated = $request->validated();

        $employee = Employee::query()
            ->whereKey($validated['employee_id'])
            ->where('company_id', $companyId)
            ->firstOrFail();

        $deployment->update([
            'employee_id' => $employee->id,
            'rank_id' => $validated['rank_id'] ?? $employee->rank_id,
            ...$this->deploymentAttributes($validated),
        ]);

        $syncSeaService->sync($deployment->fresh());

        if (($validated['redirect_to'] ?? null) === 'show') {
            return redirect()
                ->route('organization.crew-deployments.show', $deployment)
                ->with('success', 'Deployment record updated.');
        }

        return back()->with('success', 'Deployment record updated.');
    }

    public function destroy(
        Request $request,
        EmployeeDeployment $deployment,
        SyncSeaServiceFromDeployment $syncSeaService,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($deployment->company_id === $companyId, 403);

        $syncSeaService->removeLinked($deployment);
        $deployment->delete();

        return back()->with('success', 'Deployment record removed.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $deployments = EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->with(['employee.nationalityRef', 'rank', 'client', 'companyVisaType', 'vessel'])
            ->orderByDesc('joined_date')
            ->orderByDesc('created_at')
            ->get();

        return Excel::download(
            new CrewDeploymentsExport($deployments),
            'crew-deployments.xlsx',
            ExcelWriter::XLSX,
        );
    }

    /**
     * @return array<string, string>
     */
    private function listBackQuery(Request $request): array
    {
        $query = [];

        foreach (['status', 'search', 'rank_id', 'client_id', 'company_visa_type_id', 'sort', 'direction', 'per_page'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query[$key] = (string) $value;
            }
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function deploymentAttributes(array $validated): array
    {
        return [
            'client_id' => $validated['client_id'] ?? null,
            'company_visa_type_id' => $validated['company_visa_type_id'] ?? null,
            'vessel_id' => $validated['vessel_id'] ?? null,
            'arrived_date' => $validated['arrived_date'] ?? null,
            'join_standby_from' => $validated['join_standby_from'] ?? null,
            'join_standby_to' => $validated['join_standby_to'] ?? null,
            'leave_standby_from' => $validated['leave_standby_from'] ?? null,
            'leave_standby_to' => $validated['leave_standby_to'] ?? null,
            'joined_date' => $validated['joined_date'] ?? null,
            'disembarked_date' => $validated['disembarked_date'] ?? null,
            'travelled_date' => $validated['travelled_date'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ];
    }
}
