<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ApprovePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\CancelPayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\GenerateCrewPayrollRequest;
use App\Http\Requests\Organization\Payroll\ImportCrewTimesheetsRequest;
use App\Http\Requests\Organization\Payroll\MarkPayrollPeriodPaidRequest;
use App\Http\Requests\Organization\Payroll\RevertPayrollPeriodToDraftRequest;
use App\Http\Requests\Organization\Payroll\StorePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\UpsertCrewTimesheetRequest;
use App\Models\Company;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\Actions\ApprovePayrollPeriod;
use App\Support\Payroll\Actions\CancelPayrollPeriod;
use App\Support\Payroll\Actions\DeletePayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\GenerateOfficePayroll;
use App\Support\Payroll\Actions\MarkPayrollPeriodPaid;
use App\Support\Payroll\Actions\RevertPayrollPeriodToDraft;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewPayrollPagePermissions;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollHubSummary;
use App\Support\Payroll\PayrollPeriodBoardFilters;
use App\Support\Payroll\PayrollPeriodBoardQuery;
use App\Support\Payroll\PayrollPeriodDepartmentTree;
use App\Support\Payroll\PayrollPeriodListResource;
use App\Support\Payroll\PayrollPeriodRecordsSummary;
use App\Support\Payroll\PayrollPeriodResource;
use App\Support\Payroll\PayrollRecordResource;
use App\Support\Payroll\PayslipSummary;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use App\Support\Payroll\SalaryInputResource;
use App\Support\Payroll\Services\CrewPayrollSalarySheetExporter;
use App\Support\Payroll\Services\CrewTimesheetImportOrchestrator;
use App\Support\Payroll\Services\CrewTimesheetTemplateExporter;
use App\Support\Payroll\Services\OfficePayrollSalarySheetExporter;
use App\Support\Payroll\Wps\WpsExportPreview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PayrollController extends Controller
{
    use ResolvesPerPage;

    /**
     * @var list<string>
     */
    private const PAYSLIP_POLL_PROPS = [
        'payslip_summary',
        'payroll_records',
        'payroll_records_pagination',
        'payroll_records_monthly',
        'payroll_records_monthly_pagination',
    ];

    public function index(Request $request): InertiaResponse
    {
        $this->authorizePayrollHub($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $employeeCountsByCategory = $this->employeeCountsByCategory($companyId);
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $query = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->withCount(['crewTimesheets', 'payrollRecords'])
            ->latest('start_date');

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if (in_array($category, [PayrollCategory::Crew->value, PayrollCategory::Office->value], true)) {
            $query->where('payroll_category', $category);
        }

        if (in_array($status, PayrollPeriodStatus::values(), true)) {
            $query->where('status', $status);
        }

        if ($this->isValidDateFilter($dateFrom)) {
            $query->whereDate('end_date', '>=', $dateFrom);
        }

        if ($this->isValidDateFilter($dateTo)) {
            $query->whereDate('start_date', '<=', $dateTo);
        }

        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('payroll/index', [
            'periods' => collect($paginator->items())
                ->map(fn (PayrollPeriod $period) => PayrollPeriodListResource::toArray($period, $employeeCountsByCategory))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'category' => $category,
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'summary' => PayrollHubSummary::forCompany($companyId),
            'payroll_categories' => $this->payrollCategoryOptions(),
            'payroll_period_statuses' => $this->payrollPeriodStatusOptions(),
            'permissions' => [
                'create_period' => $request->user()?->can('payroll.periods.create') ?? false,
                'view_crew_timesheets' => $request->user()?->can('payroll.crew_timesheets.view') ?? false,
            ],
        ]);
    }

    public function show(
        Request $request,
        PayrollPeriod $payrollPeriod,
        PayrollPeriodBoardQuery $boardQuery,
        ProvisionDefaultSalaryInputTypes $provisionDefaultSalaryInputTypes,
    ): InertiaResponse|RedirectResponse {
        $this->authorizePayrollShow($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $payrollPeriod->load('approvedBy')->loadCount('payrollRecords');

        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $tab = trim((string) $request->query('tab', ''));
        $boardFilters = PayrollPeriodBoardFilters::fromRequest($request);
        $crewSalaryStructure = $payrollPeriod->isCrew()
            ? $boardFilters->crewSalaryStructure
            : 'daily';

        if ($this->isPayslipPollOnly($request)) {
            return $this->renderPayslipPollProps(
                $payrollPeriod,
                $companyId,
                $perPage,
                $search,
                $boardFilters,
            );
        }

        $directoryFilters = new EmployeeDirectoryFilters(
            departmentId: $boardFilters->departmentId,
            positionId: $boardFilters->positionId,
        );
        $payrollCategory = $payrollPeriod->payroll_category ?? PayrollCategory::Crew;
        $isFinalizedPeriod = in_array($payrollPeriod->status, [
            PayrollPeriodStatus::Approved,
            PayrollPeriodStatus::Paid,
        ], true);

        if ($tab === '' && $isFinalizedPeriod) {
            $params = [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ];

            if ($search !== '') {
                $params['search'] = $search;
            }

            foreach (['department_id', 'position_id', 'employee_group', 'page', 'records_page', 'monthly_records_page', 'crew_salary_structure', 'per_page'] as $key) {
                if ($request->filled($key)) {
                    $params[$key] = $request->query($key);
                }
            }

            return redirect()->route('payroll.show', $params);
        }

        $boardSearch = $search !== '' ? $search : null;

        $paginator = $boardQuery->paginate(
            companyId: $companyId,
            period: $payrollPeriod,
            search: $boardSearch,
            perPage: $perPage,
            filters: $boardFilters,
        );

        $allBoardEmployeeIds = $boardQuery->allEmployeeIds(
            companyId: $companyId,
            period: $payrollPeriod,
            search: $boardSearch,
            filters: $boardFilters,
        );

        $payrollRecordsProps = $this->paginatedPayrollRecordsProps(
            $companyId,
            $payrollPeriod,
            $search,
            $boardFilters,
            $perPage,
        );

        $payrollRecords = $payrollRecordsProps['payroll_records'];
        $payrollRecordsPagination = $payrollRecordsProps['payroll_records_pagination'];
        $payrollRecordsMonthly = $payrollRecordsProps['payroll_records_monthly'];
        $payrollRecordsMonthlyPagination = $payrollRecordsProps['payroll_records_monthly_pagination'];

        $salaryInputsByEmployee = SalaryInputResource::groupByEmployee(
            SalaryInput::query()
                ->where('company_id', $companyId)
                ->where('period_id', $payrollPeriod->id)
                ->with('salaryInputType')
                ->orderBy('id')
                ->get(),
        );

        $allPayrollRecordIds = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $payrollPeriod->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $defaultTab = $isFinalizedPeriod
            ? 'payroll'
            : ($payrollPeriod->isCrew() ? 'timesheets' : 'employees');
        $allowedTabs = $payrollPeriod->isCrew()
            ? ['timesheets', 'payroll']
            : ['employees', 'payroll'];

        $company = Company::query()->findOrFail($companyId);
        $payslipSummary = PayslipSummary::forPeriod($payrollPeriod);
        $wpsPreview = $isFinalizedPeriod && $payrollPeriod->payroll_records_count > 0
            ? app(WpsExportPreview::class)->forPeriod($company, $payrollPeriod)
            : null;

        $leaveTypes = $payrollPeriod->isOffice()
            ? LeaveType::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'color'])
                ->map(fn (LeaveType $leaveType) => [
                    'id' => $leaveType->id,
                    'name' => $leaveType->name,
                    'code' => $leaveType->code,
                    'color' => $leaveType->color,
                ])
                ->values()
                ->all()
            : [];

        $allCategoryEmployeesQuery = PayrollEmployeeQuery::activeQuery($companyId, $payrollCategory);

        if ($boardFilters->isActive()) {
            EmployeeDirectoryQuery::applyAttributeFilters(
                $allCategoryEmployeesQuery,
                $companyId,
                new EmployeeDirectoryFilters(
                    departmentId: $boardFilters->departmentId,
                    positionId: $boardFilters->positionId,
                ),
            );
        }

        $allCategoryEmployees = $allCategoryEmployeesQuery
            ->with('primaryBankAccount')
            ->get(['id', 'salary_payment_method']);
        $totalCount = $allCategoryEmployees->count();
        $cashPaymentCount = $allCategoryEmployees->filter(
            fn ($employee) => ($employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer)->excludesFromWps(),
        )->count();
        $withBankCount = $allCategoryEmployees->filter(
            fn ($employee) => $employee->primaryBankAccount !== null,
        )->count();
        $missingBankCount = $allCategoryEmployees->filter(function ($employee) {
            $paymentMethod = $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer;

            return $paymentMethod->requiresBankAccount() && $employee->primaryBankAccount === null;
        })->count();
        $employeeStats = [
            'total' => $totalCount,
            'with_bank_account' => $withBankCount,
            'missing_bank_account' => $missingBankCount,
            'cash_payment_count' => $cashPaymentCount,
        ];

        $provisionDefaultSalaryInputTypes->handle($companyId);

        return Inertia::render('payroll/show', [
            'period' => PayrollPeriodResource::toArray($payrollPeriod),
            'leave_types' => $leaveTypes,
            'rows' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'all_board_employee_ids' => $allBoardEmployeeIds,
            'payroll_records' => $payrollRecords,
            'payroll_records_pagination' => $payrollRecordsPagination,
            'payroll_records_monthly' => $payrollRecordsMonthly,
            'payroll_records_monthly_pagination' => $payrollRecordsMonthlyPagination,
            'all_payroll_record_ids' => $allPayrollRecordIds,
            'payroll_records_summary' => $payrollPeriod->payroll_records_count > 0
                ? PayrollPeriodRecordsSummary::forPeriod($payrollPeriod)
                : null,
            'salary_inputs_by_employee' => $salaryInputsByEmployee,
            'salary_input_type_options' => SalaryInputType::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'is_addition'])
                ->map(fn (SalaryInputType $type) => [
                    'value' => $type->id,
                    'label' => $type->name,
                    'code' => $type->code,
                    'is_addition' => $type->is_addition,
                ])
                ->values()
                ->all(),
            'tab' => in_array($tab, $allowedTabs, true) ? $tab : $defaultTab,
            'generation_summary' => $request->session()->get('payroll_generation')
                ?? $request->session()->get('crew_payroll_generation'),
            'search' => $search,
            'filters' => [
                'department_id' => $boardFilters->departmentId,
                'position_id' => $boardFilters->positionId,
                'employee_group' => $boardFilters->employeeGroup->value,
                'crew_salary_structure' => $crewSalaryStructure,
            ],
            'department_tree' => PayrollPeriodDepartmentTree::for(
                $companyId,
                $payrollPeriod,
                $directoryFilters,
                $boardSearch,
                $boardFilters,
            ),
            'department_tree_selected_id' => $boardFilters->departmentId !== ''
                ? (int) $boardFilters->departmentId
                : null,
            'department_tree_selected_position_id' => $boardFilters->positionId !== ''
                ? (int) $boardFilters->positionId
                : null,
            'permissions' => array_merge(CrewPayrollPagePermissions::for($request->user()), [
                'import_timesheets' => ($request->user()?->can('payroll.crew_timesheets.import') ?? false)
                    || ($request->user()?->can('payroll.crew_timesheets.create') ?? false),
                'salary_inputs_create' => ($request->user()?->can('payroll.salary_inputs.create') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false),
                'salary_inputs_update' => ($request->user()?->can('payroll.salary_inputs.update') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false),
                'salary_inputs_delete' => ($request->user()?->can('payroll.salary_inputs.delete') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false),
                'recalculate_payroll' => ($request->user()?->can('payroll.periods.recalculate') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false),
                'wps_export' => $request->user()?->can('payroll.wps.export') ?? false,
                'export_payroll' => in_array($payrollPeriod->status, [
                    PayrollPeriodStatus::Approved,
                    PayrollPeriodStatus::Paid,
                ], true)
                    && $payrollPeriod->payroll_records_count > 0
                    && (
                        ($payrollPeriod->isCrew() && ($request->user()?->can('payroll.crew_timesheets.view') ?? false))
                        || ($payrollPeriod->isOffice() && ($request->user()?->can('payroll.periods.view') ?? false))
                    ),
            ]),
            'payslip_summary' => $payslipSummary,
            'wps_preview' => $wpsPreview,
            'timesheet_draft' => $payrollPeriod->isCrew()
                ? $this->timesheetDraftFromOldInput($request)
                : null,
            'employee_stats' => $employeeStats,
        ]);
    }

    /**
     * @return array{
     *     payroll_records: list<array<string, mixed>>,
     *     payroll_records_pagination: array<string, mixed>|null,
     *     payroll_records_monthly: list<array<string, mixed>>,
     *     payroll_records_monthly_pagination: array<string, mixed>|null
     * }
     */
    private function paginatedPayrollRecordsProps(
        int $companyId,
        PayrollPeriod $payrollPeriod,
        string $search,
        PayrollPeriodBoardFilters $boardFilters,
        int $perPage,
    ): array {
        $recordsQuery = $this->payrollPeriodRecordsQuery(
            $companyId,
            $payrollPeriod,
            $search,
            $boardFilters,
        );

        $salaryInputCountsByEmployee = SalaryInput::query()
            ->where('company_id', $companyId)
            ->where('period_id', $payrollPeriod->id)
            ->selectRaw('employee_id, COUNT(*) as aggregate_count')
            ->groupBy('employee_id')
            ->pluck('aggregate_count', 'employee_id');

        $mapPayrollRecords = function ($paginator) use ($salaryInputCountsByEmployee): array {
            return collect($paginator->items())
                ->map(fn (PayrollRecord $record) => PayrollRecordResource::toArray(
                    $record,
                    (int) ($salaryInputCountsByEmployee[$record->employee_id] ?? 0),
                ))
                ->values()
                ->all();
        };

        $payrollRecords = [];
        $payrollRecordsPagination = null;
        $payrollRecordsMonthly = [];
        $payrollRecordsMonthlyPagination = null;

        if ($payrollPeriod->isCrew()) {
            $dailyRecordsPaginator = (clone $recordsQuery)
                ->crewDaily()
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'records_page')
                ->withQueryString();

            $monthlyRecordsPaginator = (clone $recordsQuery)
                ->crewMonthly()
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'monthly_records_page')
                ->withQueryString();

            $payrollRecords = $mapPayrollRecords($dailyRecordsPaginator);
            $payrollRecordsPagination = $this->paginationMeta($dailyRecordsPaginator);
            $payrollRecordsMonthly = $mapPayrollRecords($monthlyRecordsPaginator);
            $payrollRecordsMonthlyPagination = $this->paginationMeta($monthlyRecordsPaginator);
        } else {
            $recordsPaginator = $recordsQuery
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'records_page')
                ->withQueryString();

            $payrollRecords = $mapPayrollRecords($recordsPaginator);
            $payrollRecordsPagination = $this->paginationMeta($recordsPaginator);
        }

        return [
            'payroll_records' => $payrollRecords,
            'payroll_records_pagination' => $payrollRecordsPagination,
            'payroll_records_monthly' => $payrollRecordsMonthly,
            'payroll_records_monthly_pagination' => $payrollRecordsMonthlyPagination,
        ];
    }

    private function isPayslipPollOnly(Request $request): bool
    {
        if (! $request->header('X-Inertia')) {
            return false;
        }

        $partialData = trim((string) $request->header('X-Inertia-Partial-Data', ''));

        if ($partialData === '') {
            return false;
        }

        /** @var list<string> $requested */
        $requested = array_values(array_filter(array_map('trim', explode(',', $partialData))));

        if ($requested === []) {
            return false;
        }

        return empty(array_diff($requested, self::PAYSLIP_POLL_PROPS));
    }

    private function renderPayslipPollProps(
        PayrollPeriod $payrollPeriod,
        int $companyId,
        int $perPage,
        string $search,
        PayrollPeriodBoardFilters $boardFilters,
    ): InertiaResponse {
        $payrollRecordsProps = $this->paginatedPayrollRecordsProps(
            $companyId,
            $payrollPeriod,
            $search,
            $boardFilters,
            $perPage,
        );

        return Inertia::render('payroll/show', [
            'payslip_summary' => PayslipSummary::forPeriod($payrollPeriod),
            ...$payrollRecordsProps,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payrollPeriodRecordsQuery(
        int $companyId,
        PayrollPeriod $payrollPeriod,
        string $search,
        PayrollPeriodBoardFilters $boardFilters,
    ): Builder {
        $recordsQuery = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $payrollPeriod->id)
            ->with([
                'employee.primaryBankAccount.bank:id,name',
                'employee.department.parent:id,name',
                'employee.position:id,title',
            ]);

        if ($search !== '') {
            $recordsQuery->whereHas('employee', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%");
            });
        }

        if ($boardFilters->isActive()) {
            $recordsQuery->whereHas('employee', function (Builder $query) use ($companyId, $boardFilters): void {
                EmployeeDirectoryQuery::applyAttributeFilters(
                    $query,
                    $companyId,
                    new EmployeeDirectoryFilters(
                        departmentId: $boardFilters->departmentId,
                        positionId: $boardFilters->positionId,
                    ),
                );
            });
        }

        return $recordsQuery;
    }

    private function timesheetDraftFromOldInput(Request $request): ?array
    {
        if ($request->old('employee_id') === null) {
            return null;
        }

        return [
            'period_id' => (int) $request->old('period_id'),
            'employee_id' => (int) $request->old('employee_id'),
            'standby_from' => $request->old('standby_from'),
            'standby_to' => $request->old('standby_to'),
            'standby_days' => $request->old('standby_days'),
            'onsite_from' => $request->old('onsite_from'),
            'onsite_to' => $request->old('onsite_to'),
            'onsite_days' => $request->old('onsite_days'),
            'overtime_hours' => $request->old('overtime_hours'),
            'additional_amount' => $request->old('additional_amount'),
            'deduction_amount' => $request->old('deduction_amount'),
            'remarks' => $request->old('remarks'),
        ];
    }

    public function storePeriod(StorePayrollPeriodRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        PayrollPeriod::query()->create([
            'company_id' => $companyId,
            'payroll_category' => $request->validated('payroll_category'),
            'name' => $request->validated('name'),
            'start_date' => $request->validated('start_date'),
            'end_date' => $request->validated('end_date'),
            'payment_date' => $request->validated('payment_date'),
            'notes' => $request->validated('notes'),
            'status' => PayrollPeriodStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('payroll.index')
            ->with('success', 'Payroll period created.');
    }

    public function storeTimesheet(
        UpsertCrewTimesheetRequest $request,
        PayrollPeriod $payrollPeriod,
        UpsertCrewTimesheet $upsertCrewTimesheet,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless((int) $request->validated('period_id') === $payrollPeriod->id, 422);
        abort_unless($payrollPeriod->isCrew(), 404);

        $upsertCrewTimesheet->handle(
            $payrollPeriod,
            $request->employee(),
            $request->timesheetData(),
        );

        return back();
    }

    public function importTemplate(
        PayrollPeriod $payrollPeriod,
        CrewTimesheetTemplateExporter $exporter,
    ) {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless($payrollPeriod->isCrew(), 404);

        $result = $exporter->export($companyId, $payrollPeriod);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function exportPayroll(
        PayrollPeriod $payrollPeriod,
        CrewPayrollSalarySheetExporter $crewExporter,
        OfficePayrollSalarySheetExporter $officeExporter,
    ) {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless(in_array($payrollPeriod->status, [
            PayrollPeriodStatus::Approved,
            PayrollPeriodStatus::Paid,
        ], true), 403);

        $payrollPeriod->loadCount('payrollRecords');

        abort_unless($payrollPeriod->payroll_records_count > 0, 422);

        if ($payrollPeriod->isCrew()) {
            abort_unless(request()->user()?->can('payroll.crew_timesheets.view'), 403);

            $result = $crewExporter->export($companyId, $payrollPeriod);

            return response()
                ->download($result['path'], $result['filename'])
                ->deleteFileAfterSend();
        }

        abort_unless($payrollPeriod->isOffice(), 404);
        abort_unless(request()->user()?->can('payroll.periods.view'), 403);

        $result = $officeExporter->export($companyId, $payrollPeriod);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importPreview(
        ImportCrewTimesheetsRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetImportOrchestrator $orchestrator,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        try {
            $result = $orchestrator->preview($companyId, $payrollPeriod, $request->file('file'));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }

    public function importTimesheets(
        ImportCrewTimesheetsRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetImportOrchestrator $orchestrator,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        try {
            $result = $orchestrator->execute($companyId, $payrollPeriod, $request->file('file'));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        $message = "Imported {$result['imported']} crew timesheet(s).";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} row(s) skipped.";
        }

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'timesheets',
            ])
            ->with('success', $message);
    }

    public function generatePayroll(
        GenerateCrewPayrollRequest $request,
        PayrollPeriod $payrollPeriod,
        GenerateCrewPayroll $generateCrewPayroll,
        GenerateOfficePayroll $generateOfficePayroll,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $result = $payrollPeriod->isCrew()
            ? $generateCrewPayroll->handle(
                $payrollPeriod,
                $request->input('excluded_employee_ids', []),
            )
            : $generateOfficePayroll->handle(
                $payrollPeriod,
                $request->input('excluded_employee_ids', []),
                $request->input('employee_dates', []),
            );

        $message = $result->generatedCount > 0
            ? "Generated payroll for {$result->generatedCount} employee(s)."
            : 'No payroll records were generated.';

        if ($result->skippedCount > 0) {
            $skipReason = $payrollPeriod->isCrew() ? 'no timesheet' : 'no attendance';
            $message .= " {$result->skippedCount} employee(s) skipped ({$skipReason}).";
        }

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ])
            ->with('success', $message)
            ->with('payroll_generation', $result->toSessionArray());
    }

    public function destroyPayrollRecord(
        Request $request,
        PayrollPeriod $payrollPeriod,
        PayrollRecord $payrollRecord,
        DeletePayrollRecord $deletePayrollRecord,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $deletePayrollRecord->handle($payrollPeriod, $payrollRecord);

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ])
            ->with('success', 'Employee removed from this pay run.');
    }

    public function revertToDraft(
        RevertPayrollPeriodToDraftRequest $request,
        PayrollPeriod $payrollPeriod,
        RevertPayrollPeriodToDraft $revertPayrollPeriodToDraft,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $revertPayrollPeriodToDraft->handle($payrollPeriod);

        $defaultTab = $payrollPeriod->isCrew() ? 'timesheets' : 'employees';

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => $defaultTab,
            ])
            ->with(
                'success',
                $payrollPeriod->isCrew()
                    ? 'Pay period reverted to draft. Timesheets and payroll records were cleared.'
                    : 'Pay period reverted to draft. Payroll records were cleared.',
            );
    }

    public function approve(
        ApprovePayrollPeriodRequest $request,
        PayrollPeriod $payrollPeriod,
        ApprovePayrollPeriod $approvePayrollPeriod,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $approvePayrollPeriod->handle($payrollPeriod, $user);

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ])
            ->with('success', 'Pay period approved. Payslips are being generated in the background.');
    }

    public function markPaid(
        MarkPayrollPeriodPaidRequest $request,
        PayrollPeriod $payrollPeriod,
        MarkPayrollPeriodPaid $markPayrollPeriodPaid,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $proofFiles = $request->file('payment_proofs');
        if (! is_array($proofFiles) && $request->hasFile('payment_proof')) {
            $proofFiles = [$request->file('payment_proof')];
        }

        $markPayrollPeriodPaid->handle($payrollPeriod, $proofFiles);

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ])
            ->with('success', 'Pay period marked as paid.');
    }

    public function downloadPaymentProof(Request $request, PayrollPeriod $payrollPeriod)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $paths = $payrollPeriod->payment_proof_paths ?? [];
        if (empty($paths) && filled($payrollPeriod->payment_proof_path)) {
            $paths = [$payrollPeriod->payment_proof_path];
        }

        $index = (int) $request->query('index', 0);
        $targetPath = $paths[$index] ?? ($paths[0] ?? null);

        abort_unless($targetPath !== null, 404);
        abort_unless(Storage::exists($targetPath), 404);

        return Storage::download($targetPath);
    }

    public function cancel(
        CancelPayrollPeriodRequest $request,
        PayrollPeriod $payrollPeriod,
        CancelPayrollPeriod $cancelPayrollPeriod,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $cancelPayrollPeriod->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Pay period cancelled.');
    }

    /**
     * @return array<string, int>
     */
    private function employeeCountsByCategory(int $companyId): array
    {
        return [
            PayrollCategory::Crew->value => PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Crew),
            PayrollCategory::Office->value => PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Office),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function payrollCategoryOptions(): array
    {
        return collect(PayrollCategory::cases())
            ->map(fn (PayrollCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function payrollPeriodStatusOptions(): array
    {
        return collect(PayrollPeriodStatus::cases())
            ->map(fn (PayrollPeriodStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->values()
            ->all();
    }

    private function isValidDateFilter(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function authorizePayrollHub(Request $request): void
    {
        abort_unless(
            $request->user()?->can('payroll.periods.view')
            || $request->user()?->can('payroll.crew_timesheets.view'),
            403,
        );
    }

    private function authorizePayrollShow(Request $request): void
    {
        abort_unless(
            $request->user()?->can('payroll.periods.view')
            || $request->user()?->can('payroll.crew_timesheets.view'),
            403,
        );
    }
}
