<?php

namespace App\Http\Controllers\Organization;

use App\Exports\CrewDeploymentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewDeployment\ImportEmployeeDeploymentsRequest;
use App\Http\Requests\Organization\CrewDeployment\StoreEmployeeDeploymentRequest;
use App\Http\Requests\Organization\CrewDeployment\UpdateEmployeeDeploymentRequest;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use App\Support\CrewDeployments\CrewDeploymentBoardQuery;
use App\Support\CrewDeployments\ImportEmployeeDeploymentsFromSpreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        $view = $request->string('view')->toString() ?: 'current';
        $status = $request->string('status')->toString() ?: null;
        $search = $request->string('search')->toString() ?: null;

        $result = $boardQuery->paginate(
            companyId: $companyId,
            view: in_array($view, ['current', 'all'], true) ? $view : 'current',
            status: $status,
            search: $search,
            rankId: $request->integer('rank_id') ?: null,
            clientId: $request->integer('client_id') ?: null,
            companyVisaTypeId: $request->integer('company_visa_type_id') ?: null,
            perPage: min(max($request->integer('per_page', 25), 10), 100),
        );

        $paginator = $result['paginator'];

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
                'view' => $view,
                'status' => $status,
                'search' => $search,
                'rank_id' => $request->integer('rank_id') ?: null,
                'client_id' => $request->integer('client_id') ?: null,
                'company_visa_type_id' => $request->integer('company_visa_type_id') ?: null,
            ],
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'employee_no', 'name', 'rank_id']),
            'ranks' => Rank::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'company_visa_types' => CompanyVisaType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => $request->user()?->can('crew_deployments.manage') ?? false,
            ],
        ]);
    }

    public function store(StoreEmployeeDeploymentRequest $request): RedirectResponse
    {
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

        EmployeeDeployment::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            'rank_id' => $validated['rank_id'] ?? $employee->rank_id,
            ...$this->deploymentAttributes($validated),
        ]);

        return back()->with('success', 'Deployment record added.');
    }

    public function update(
        UpdateEmployeeDeploymentRequest $request,
        EmployeeDeployment $deployment,
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

        return back()->with('success', 'Deployment record updated.');
    }

    public function destroy(Request $request, EmployeeDeployment $deployment): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($deployment->company_id === $companyId, 403);

        $deployment->delete();

        return back()->with('success', 'Deployment record removed.');
    }

    public function importTemplate(): Response
    {
        $csv = implode(',', [
            'emp no',
            'name',
            'rank',
            'nationality',
            'date of hire',
            'date arrived',
            'standby from',
            'standby to',
            'date joined',
            'date disembarked',
            'date travelled',
            'vessel',
            'company visa type',
            'client',
            'remarks',
        ])."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="crew-deployments-import-template.csv"',
        ]);
    }

    public function import(
        ImportEmployeeDeploymentsRequest $request,
        ImportEmployeeDeploymentsFromSpreadsheet $importer,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();

        $result = $importer->import((string) $path, $companyId);

        if ($result['imported'] === 0) {
            $message = $result['errors'][0] ?? 'No deployment rows were imported.';

            return back()->withErrors(['file' => $message]);
        }

        $message = "Imported {$result['imported']} deployment row(s).";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']} row(s).";
        }

        return back()->with('success', $message);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $deployments = EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->with(['employee.nationalityRef', 'rank', 'client', 'companyVisaType'])
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function deploymentAttributes(array $validated): array
    {
        return [
            'client_id' => $validated['client_id'] ?? null,
            'company_visa_type_id' => $validated['company_visa_type_id'] ?? null,
            'vessel_name' => $validated['vessel_name'] ?? null,
            'hire_date' => $validated['hire_date'] ?? null,
            'arrived_date' => $validated['arrived_date'] ?? null,
            'standby_from' => $validated['standby_from'] ?? null,
            'standby_to' => $validated['standby_to'] ?? null,
            'joined_date' => $validated['joined_date'] ?? null,
            'disembarked_date' => $validated['disembarked_date'] ?? null,
            'travelled_date' => $validated['travelled_date'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ];
    }
}
