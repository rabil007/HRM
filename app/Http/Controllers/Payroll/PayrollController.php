<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodCreationSource;
use App\Enums\PayrollPeriodStatus;
use App\Enums\RecentItemType;
use App\Enums\SalaryPaymentMethod;
use App\Enums\SavedViewPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ApprovePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\CancelPayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\GenerateCrewPayrollRequest;
use App\Http\Requests\Organization\Payroll\ImportCrewTimesheetsRequest;
use App\Http\Requests\Organization\Payroll\MarkPayrollPeriodPaidRequest;
use App\Http\Requests\Organization\Payroll\RevertPayrollPeriodToApprovedRequest;
use App\Http\Requests\Organization\Payroll\RevertPayrollPeriodToDraftRequest;
use App\Http\Requests\Organization\Payroll\RevertPayrollPeriodToProcessingRequest;
use App\Http\Requests\Organization\Payroll\StorePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\UpsertCrewTimesheetRequest;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Models\User;
use App\Support\Contracts\ContractSalaryStructureFilter;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\Actions\ApprovePayrollPeriod;
use App\Support\Payroll\Actions\CancelPayrollPeriod;
use App\Support\Payroll\Actions\DeletePayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\GenerateOfficePayroll;
use App\Support\Payroll\Actions\MarkPayrollPeriodPaid;
use App\Support\Payroll\Actions\RevertPayrollPeriodToApproved;
use App\Support\Payroll\Actions\RevertPayrollPeriodToDraft;
use App\Support\Payroll\Actions\RevertPayrollPeriodToProcessing;
use App\Support\Payroll\Actions\UpdatePayrollPeriodCrewTimesheetMode;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\BuildCrewPayrollCoverageSummary;
use App\Support\Payroll\ClearableManualImportCrewTimesheetsQuery;
use App\Support\Payroll\CrewPayrollPagePermissions;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewQuery;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationSummaryResource;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollHubSummary;
use App\Support\Payroll\PayrollPeriodBoardFilters;
use App\Support\Payroll\PayrollPeriodBoardQuery;
use App\Support\Payroll\PayrollPeriodDepartmentTree;
use App\Support\Payroll\PayrollPeriodListResource;
use App\Support\Payroll\PayrollPeriodRecordsSummary;
use App\Support\Payroll\PayrollPeriodResource;
use App\Support\Payroll\PayrollRecordAccess;
use App\Support\Payroll\PayrollRecordResource;
use App\Support\Payroll\PayslipSummary;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use App\Support\Payroll\RegularPayrollPeriodKey;
use App\Support\Payroll\SalaryInputResource;
use App\Support\Payroll\Services\CrewPayrollSalarySheetExporter;
use App\Support\Payroll\Services\CrewTimesheetImportOrchestrator;
use App\Support\Payroll\Services\CrewTimesheetTemplateExporter;
use App\Support\Payroll\Services\OfficePayrollSalarySheetExporter;
use App\Support\Payroll\Wps\WpsExportPreview;
use App\Support\RecentItems\RecordRecentItem;
use App\Support\SavedViews\ApplyDefaultSavedView;
use App\Support\SavedViews\SavedViewsForPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $this->authorizePayrollHub($request);

        $redirect = ApplyDefaultSavedView::maybeRedirect($request, SavedViewPage::Payroll);

        if ($redirect !== null) {
            return $redirect;
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $includeFinancial = (bool) ($user?->can('payroll.periods.view'));
        $perPage = $this->resolvePerPage($request);
        $employeeCountsByCategory = $this->employeeCountsByCategory($companyId, $user);
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $showAll = $request->boolean('all');

        if (! $includeFinancial) {
            $category = PayrollCategory::Crew->value;
        }

        $monthsInput = $request->input('months');
        $months = [];
        if (is_array($monthsInput)) {
            $months = array_values(array_unique(array_filter(
                $monthsInput,
                fn ($m) => is_string($m) && (bool) preg_match('/^\d{4}-\d{2}$/', $m)
            )));
        } elseif (is_string($monthsInput) && $monthsInput !== '') {
            $months = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $monthsInput)),
                fn ($m) => (bool) preg_match('/^\d{4}-\d{2}$/', $m)
            )));
        }
        sort($months);

        if (! $showAll && $months === [] && $dateFrom === '' && $dateTo === '') {
            $company = Company::query()->find($companyId);
            $timezone = $company?->timezone ?? config('app.timezone', 'Asia/Dubai');
            $now = CarbonImmutable::now($timezone);
            $months = [$now->format('Y-m')];
            $dateFrom = $now->startOfMonth()->toDateString();
            $dateTo = $now->endOfMonth()->toDateString();
        } elseif ($months !== []) {
            $firstMonth = $months[0];
            $lastMonth = end($months);
            $dateFrom = CarbonImmutable::parse($firstMonth.'-01')->startOfMonth()->toDateString();
            $dateTo = CarbonImmutable::parse($lastMonth.'-01')->endOfMonth()->toDateString();
        }

        $query = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->withCount([
                'crewTimesheets',
                'payrollRecords',
                'payrollRecords as daily_payroll_records_count' => function ($recordsQuery): void {
                    $recordsQuery->crewDaily();
                },
                'crewTimesheets as daily_crew_timesheets_count' => function ($timesheetQuery): void {
                    $timesheetQuery->whereHas('employee.currentContract', function ($contractQuery): void {
                        $contractQuery->where('payroll_category', PayrollCategory::Crew->value);
                        ContractSalaryStructureFilter::apply(
                            $contractQuery,
                            ContractSalaryStructureFilter::DAILY,
                        );
                    });
                },
                'payrollRecords as daily_payroll_records_with_timesheet_count' => function ($recordsQuery): void {
                    $recordsQuery
                        ->crewDaily()
                        ->whereHas('employee.crewTimesheets', function ($timesheetQuery): void {
                            $timesheetQuery->whereColumn(
                                'crew_timesheets.period_id',
                                'payroll_records.period_id',
                            );
                        });
                },
            ])
            ->latest('start_date');

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if (! $includeFinancial) {
            $query->where('payroll_category', PayrollCategory::Crew);
        } elseif (in_array($category, [PayrollCategory::Crew->value, PayrollCategory::Office->value], true)) {
            $query->where('payroll_category', $category);
        }

        if (in_array($status, PayrollPeriodStatus::values(), true)) {
            $query->where('status', $status);
        }

        if ($months !== []) {
            $query->where(function (Builder $dateQuery) use ($months): void {
                foreach ($months as $month) {
                    $start = CarbonImmutable::parse($month.'-01')->startOfMonth()->toDateString();
                    $end = CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString();
                    $dateQuery->orWhere(function (Builder $mQuery) use ($start, $end): void {
                        $mQuery->whereDate('end_date', '>=', $start)
                            ->whereDate('start_date', '<=', $end);
                    });
                }
            });
        } else {
            if ($this->isValidDateFilter($dateFrom)) {
                $query->whereDate('end_date', '>=', $dateFrom);
            }

            if ($this->isValidDateFilter($dateTo)) {
                $query->whereDate('start_date', '<=', $dateTo);
            }
        }

        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('payroll/index', [
            'periods' => collect($paginator->items())
                ->map(fn (PayrollPeriod $period) => PayrollPeriodListResource::toArray(
                    $period,
                    $employeeCountsByCategory,
                    $includeFinancial,
                    $user,
                ))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'category' => $category,
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'months' => $months,
                'all' => $showAll ? '1' : '',
            ],
            'summary' => PayrollHubSummary::forCompany($companyId, $dateFrom, $dateTo, $months, $user),
            'payroll_categories' => $this->payrollCategoryOptions($includeFinancial),
            'payroll_period_statuses' => $this->payrollPeriodStatusOptions(),
            'permissions' => [
                'create_period' => $user?->can('payroll.periods.create') ?? false,
                'view_crew_timesheets' => $user?->can('payroll.crew_timesheets.view') ?? false,
                'view_financial' => $includeFinancial,
            ],
            'saved_views' => SavedViewsForPage::props($user, $companyId, SavedViewPage::Payroll),
        ]);
    }

    public function show(
        Request $request,
        PayrollPeriod $payrollPeriod,
        PayrollPeriodBoardQuery $boardQuery,
        ProvisionDefaultSalaryInputTypes $provisionDefaultSalaryInputTypes,
        CrewTimesheetPreparationReviewQuery $crewTimelineReviewQuery,
        CrewTimesheetPreparationSummaryResource $crewTimelineSummaryResource,
        BuildCrewPayrollCoverageSummary $buildCrewPayrollCoverageSummary,
        ClearableManualImportCrewTimesheetsQuery $clearableManualImportCrewTimesheetsQuery,
        RecordRecentItem $recordRecentItem,
    ): InertiaResponse|RedirectResponse {
        $this->authorizePayrollShow($request, $payrollPeriod);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $user = $request->user();

        if (! $this->isPayslipPollOnly($request) && $user !== null) {
            $recordRecentItem->handle($user, $companyId, RecentItemType::PayrollPeriod, $payrollPeriod->id);
        }

        $payrollPeriod->load('approvedBy')->loadCount('payrollRecords');

        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $includeFinancial = (bool) ($user?->can('payroll.periods.view'));
        $boardFilters = PayrollPeriodBoardFilters::fromRequest($request, $includeFinancial);
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
                $user,
            );
        }

        $directoryFilters = new EmployeeDirectoryFilters(
            departmentId: $boardFilters->departmentId,
            positionId: $boardFilters->positionId,
            companyVisaTypeId: $boardFilters->companyVisaTypeId,
        );
        $payrollCategory = $payrollPeriod->payroll_category ?? PayrollCategory::Crew;
        $isFinalizedPeriod = in_array($payrollPeriod->status, [
            PayrollPeriodStatus::Approved,
            PayrollPeriodStatus::Paid,
        ], true);

        $boardSearch = $search !== '' ? $search : null;

        $paginator = $boardQuery->paginate(
            companyId: $companyId,
            period: $payrollPeriod,
            search: $boardSearch,
            perPage: $perPage,
            filters: $boardFilters,
            user: $user,
            includeFinancial: $includeFinancial,
        );

        $allBoardEmployeeIds = $boardQuery->allEmployeeIds(
            companyId: $companyId,
            period: $payrollPeriod,
            search: $boardSearch,
            filters: $boardFilters,
            user: $user,
        );

        $payrollRecordsProps = $includeFinancial
            ? $this->paginatedPayrollRecordsProps(
                $companyId,
                $payrollPeriod,
                $search,
                $boardFilters,
                $perPage,
                $user,
            )
            : [
                'payroll_records' => [],
                'payroll_records_pagination' => null,
                'payroll_records_monthly' => [],
                'payroll_records_monthly_pagination' => null,
            ];

        $payrollRecords = $payrollRecordsProps['payroll_records'];
        $payrollRecordsPagination = $payrollRecordsProps['payroll_records_pagination'];
        $payrollRecordsMonthly = $payrollRecordsProps['payroll_records_monthly'];
        $payrollRecordsMonthlyPagination = $payrollRecordsProps['payroll_records_monthly_pagination'];

        $salaryInputsByEmployee = [];

        if ($includeFinancial) {
            $salaryInputsQuery = SalaryInput::query()
                ->where('company_id', $companyId)
                ->where('period_id', $payrollPeriod->id)
                ->with('salaryInputType')
                ->orderBy('id');

            if ($user !== null) {
                EmployeeVisibilityScope::whereHas($salaryInputsQuery, $user, $companyId, 'employee');
            }

            $salaryInputsByEmployee = SalaryInputResource::groupByEmployee($salaryInputsQuery->get());
        }

        $allPayrollRecordIds = $includeFinancial
            ? PayrollRecordAccess::apply(
                PayrollRecord::query()
                    ->where('company_id', $companyId)
                    ->where('period_id', $payrollPeriod->id)
                    ->orderBy('id'),
                $user,
                $companyId,
            )
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
            : [];

        $company = Company::query()->findOrFail($companyId);
        $payslipSummary = $includeFinancial
            ? PayslipSummary::forPeriod($payrollPeriod, $user)
            : null;
        $wpsPreview = $includeFinancial
            && $isFinalizedPeriod
            && $payrollPeriod->payroll_records_count > 0
            ? app(WpsExportPreview::class)->forPeriod($company, $payrollPeriod, $user)
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

        // Scope to the user's Role Employee Access Scope.
        if ($user !== null) {
            EmployeeVisibilityScope::apply($allCategoryEmployeesQuery, $user, $companyId);
        }

        if ($boardFilters->isActive()) {
            EmployeeDirectoryQuery::applyAttributeFilters(
                $allCategoryEmployeesQuery,
                $companyId,
                new EmployeeDirectoryFilters(
                    departmentId: $boardFilters->departmentId,
                    positionId: $boardFilters->positionId,
                    companyVisaTypeId: $boardFilters->companyVisaTypeId,
                ),
            );
        }

        if (
            $payrollPeriod->isCrew()
            && ContractSalaryStructureFilter::isValid($crewSalaryStructure)
        ) {
            $allCategoryEmployeesQuery->whereHas(
                'currentContract',
                function (Builder $contractQuery) use ($crewSalaryStructure): void {
                    ContractSalaryStructureFilter::apply($contractQuery, $crewSalaryStructure);
                },
            );
        }

        if ($includeFinancial) {
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
        } else {
            $employeeStats = [
                'total' => $allCategoryEmployeesQuery->count(),
            ];
        }

        $provisionDefaultSalaryInputTypes->handle($companyId);

        $generationSummary = $payrollPeriod->isCrew()
            ? $buildCrewPayrollCoverageSummary->handle($payrollPeriod, $companyId, $user)
            : null;

        return Inertia::render('payroll/show', [
            'period' => PayrollPeriodResource::toArray($payrollPeriod, $generationSummary, $includeFinancial, $user),
            'leave_types' => $leaveTypes,
            'rows' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'all_board_employee_ids' => $allBoardEmployeeIds,
            'payroll_records' => $payrollRecords,
            'payroll_records_pagination' => $payrollRecordsPagination,
            'payroll_records_monthly' => $payrollRecordsMonthly,
            'payroll_records_monthly_pagination' => $payrollRecordsMonthlyPagination,
            'all_payroll_record_ids' => $allPayrollRecordIds,
            'payroll_records_summary' => $includeFinancial && $payrollPeriod->payroll_records_count > 0
                ? PayrollPeriodRecordsSummary::forPeriod($payrollPeriod, $user)
                : null,
            'salary_inputs_by_employee' => $salaryInputsByEmployee,
            'salary_input_type_options' => $includeFinancial
                ? SalaryInputType::query()
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
                    ->all()
                : [],
            'generation_summary' => $request->session()->get('payroll_generation')
                ?? $request->session()->get('crew_payroll_generation'),
            'search' => $search,
            'filters' => [
                'department_id' => $boardFilters->departmentId,
                'position_id' => $boardFilters->positionId,
                'company_visa_type_id' => $boardFilters->companyVisaTypeId,
                'employee_group' => $boardFilters->employeeGroup->value,
                'crew_salary_structure' => $crewSalaryStructure,
                'crew_timesheet_filter' => $boardFilters->crewTimesheetFilter?->value ?? '',
            ],
            'company_visa_types' => CompanyVisaType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'department_tree' => PayrollPeriodDepartmentTree::for(
                $companyId,
                $payrollPeriod,
                $directoryFilters,
                $boardSearch,
                $boardFilters,
                $user,
            ),
            'department_tree_selected_id' => $boardFilters->departmentId !== ''
                ? (int) $boardFilters->departmentId
                : null,
            'department_tree_selected_position_id' => $boardFilters->positionId !== ''
                ? (int) $boardFilters->positionId
                : null,
            'permissions' => array_merge(CrewPayrollPagePermissions::for($request->user()), [
                'view_financial' => $includeFinancial,
                'edit_monetary_timesheet_fields' => $request->user()?->can('payroll.periods.update') ?? false,
                'import_timesheets' => ($request->user()?->can('payroll.crew_timesheets.import') ?? false)
                    || ($request->user()?->can('payroll.crew_timesheets.create') ?? false),
                'prepare_timeline' => ($request->user()?->can('payroll.crew_timesheets.prepare') ?? false)
                    && EmployeeVisibilityScope::hasUnrestrictedAccess($request->user(), $companyId),
                'view_timeline' => $request->user()?->can('payroll.crew_timesheets.view') ?? false,
                'salary_inputs_create' => $includeFinancial && (
                    ($request->user()?->can('payroll.salary_inputs.create') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false)
                ),
                'salary_inputs_update' => $includeFinancial && (
                    ($request->user()?->can('payroll.salary_inputs.update') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false)
                ),
                'salary_inputs_delete' => $includeFinancial && (
                    ($request->user()?->can('payroll.salary_inputs.delete') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false)
                ),
                'recalculate_payroll' => $includeFinancial && (
                    ($request->user()?->can('payroll.periods.recalculate') ?? false)
                    || ($request->user()?->can('payroll.periods.update') ?? false)
                ),
                'wps_export' => $includeFinancial && ($request->user()?->can('payroll.wps.export') ?? false),
                'export_payroll' => $includeFinancial
                    && in_array($payrollPeriod->status, [
                        PayrollPeriodStatus::Approved,
                        PayrollPeriodStatus::Paid,
                    ], true)
                    && $payrollPeriod->payroll_records_count > 0
                    && (
                        ($payrollPeriod->isCrew() && ($request->user()?->can('payroll.crew_timesheets.view') ?? false))
                        || ($payrollPeriod->isOffice() && ($request->user()?->can('payroll.periods.view') ?? false))
                    ),
                'payslips_generate' => $includeFinancial && ($request->user()?->can('payroll.payslips.generate') ?? false),
                'payslips_email' => $includeFinancial && ($request->user()?->can('payroll.payslips.email') ?? false),
            ]),
            'payslip_summary' => $payslipSummary,
            'wps_preview' => $wpsPreview,
            'timesheet_draft' => $payrollPeriod->isCrew()
                ? $this->timesheetDraftFromOldInput($request)
                : null,
            'crew_timeline_preparation' => $payrollPeriod->usesCrewOperationsTimesheets()
                && ($request->user()?->can('payroll.crew_timesheets.view') ?? false)
                ? $crewTimelineSummaryResource->toArray(
                    $crewTimelineReviewQuery->latestForPeriod(
                        $payrollPeriod,
                        $companyId,
                    ),
                    $payrollPeriod,
                )
                : null,
            'crew_timesheet_mode_options' => collect(CrewTimesheetMode::cases())
                ->map(fn (CrewTimesheetMode $mode) => [
                    'value' => $mode->value,
                    'label' => $mode->label(),
                ])
                ->values()
                ->all(),
            'clearable_timesheet_count' => $payrollPeriod->isCrew() && $payrollPeriod->status === PayrollPeriodStatus::Draft
                ? $clearableManualImportCrewTimesheetsQuery->count($payrollPeriod, $companyId)
                : 0,
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
        ?User $user = null,
    ): array {
        $recordsQuery = $this->payrollPeriodRecordsQuery(
            $companyId,
            $payrollPeriod,
            $search,
            $boardFilters,
            $user,
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
        ?User $user = null,
    ): InertiaResponse {
        $payrollRecordsProps = $this->paginatedPayrollRecordsProps(
            $companyId,
            $payrollPeriod,
            $search,
            $boardFilters,
            $perPage,
            $user,
        );

        return Inertia::render('payroll/show', [
            'payslip_summary' => PayslipSummary::forPeriod($payrollPeriod, $user),
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
        ?User $user = null,
    ): Builder {
        $recordsQuery = PayrollRecordAccess::apply(
            PayrollRecord::query()
                ->where('company_id', $companyId)
                ->where('period_id', $payrollPeriod->id)
                ->with([
                    'employee.primaryBankAccount.bank:id,name',
                    'employee.department.parent:id,name',
                    'employee.position:id,title',
                ]),
            $user,
            $companyId,
        );

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
                        companyVisaTypeId: $boardFilters->companyVisaTypeId,
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
            'sign_on_standby_from' => $request->old('sign_on_standby_from'),
            'sign_on_standby_to' => $request->old('sign_on_standby_to'),
            'onsite_from' => $request->old('onsite_from'),
            'onsite_to' => $request->old('onsite_to'),
            'sign_off_standby_from' => $request->old('sign_off_standby_from'),
            'sign_off_standby_to' => $request->old('sign_off_standby_to'),
            'unpaid_leave_days' => $request->old('unpaid_leave_days'),
            'overtime_hours' => $request->old('overtime_hours'),
            'additional_amount' => $request->old('additional_amount'),
            'deduction_amount' => $request->old('deduction_amount'),
            'remarks' => $request->old('remarks'),
        ];
    }

    public function storePeriod(
        StorePayrollPeriodRequest $request,
        UpdatePayrollPeriodCrewTimesheetMode $resolveMode,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $category = PayrollCategory::from($request->validated('payroll_category'));
        $mode = $resolveMode->resolveModeForCreate($category);
        $startDate = $request->validated('start_date');
        $endDate = $request->validated('end_date');
        $regularPeriodKey = RegularPayrollPeriodKey::tryFromDates(
            $companyId,
            $category,
            $startDate,
            $endDate,
        );

        try {
            PayrollPeriod::query()->create([
                'company_id' => $companyId,
                'payroll_category' => $category,
                'crew_timesheet_mode' => $mode,
                'name' => $request->validated('name'),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'notes' => $request->validated('notes'),
                'status' => PayrollPeriodStatus::Draft,
                'creation_source' => PayrollPeriodCreationSource::Manual,
                'automatic_period_key' => null,
                'regular_period_key' => $regularPeriodKey,
                'created_by' => $request->user()?->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($regularPeriodKey === null) {
                throw $exception;
            }

            $existing = RegularPayrollPeriodKey::findExisting($companyId, $regularPeriodKey);

            throw ValidationException::withMessages([
                'start_date' => sprintf(
                    'A regular %s payroll period for this month already exists (%s). Open the existing period instead of creating another.',
                    $category->label(),
                    $existing?->name ?? 'existing period',
                ),
            ]);
        }

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
            $request->user()?->id,
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
        abort_unless(
            request()->user()?->can('payroll.crew_timesheets.import')
            || request()->user()?->can('payroll.crew_timesheets.create'),
            403,
        );

        $result = $exporter->export($companyId, $payrollPeriod, request()->user());

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
            abort_unless(request()->user()?->can('payroll.periods.view'), 403);

            $result = $crewExporter->export($companyId, $payrollPeriod, request()->user());

            return response()
                ->download($result['path'], $result['filename'])
                ->deleteFileAfterSend();
        }

        abort_unless($payrollPeriod->isOffice(), 404);
        abort_unless(request()->user()?->can('payroll.periods.view'), 403);

        $result = $officeExporter->export($companyId, $payrollPeriod, request()->user());

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
            $result = $orchestrator->preview($companyId, $payrollPeriod, $request->file('file'), $request->user());
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
            $result = $orchestrator->execute(
                $companyId,
                $payrollPeriod,
                $request->file('file'),
                $request->user()?->id,
                $request->user(),
            );
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
            ->route('payroll.show', $payrollPeriod)
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
                $request->user(),
            )
            : $generateOfficePayroll->handle(
                $payrollPeriod,
                $request->input('excluded_employee_ids', []),
                $request->input('employee_dates', []),
                $request->user(),
            );

        $message = $result->generatedCount > 0
            ? "Payroll generated for {$result->generatedCount} employee(s)."
            : 'No payroll records were generated.';

        if ($payrollPeriod->isCrew()) {
            $skipParts = [];

            if ($result->skippedMissingTimesheetCount > 0) {
                $skipParts[] = "{$result->skippedMissingTimesheetCount} without timesheets";
            }

            if ($result->skippedExcludedCount > 0) {
                $skipParts[] = "{$result->skippedExcludedCount} explicitly excluded";
            }

            if ($skipParts !== []) {
                $message .= ' Skipped: '.implode('; ', $skipParts).'.';
            }
        } elseif ($result->skippedCount > 0) {
            $message .= " {$result->skippedCount} employee(s) skipped (no attendance).";
        }

        return redirect()
            ->route('payroll.show', $payrollPeriod)
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

        PayrollRecordAccess::assertRecord($request->user(), $payrollRecord, $companyId);

        $deletePayrollRecord->handle($payrollPeriod, $payrollRecord);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Employee removed from this pay run.');
    }

    public function revertToDraft(
        RevertPayrollPeriodToDraftRequest $request,
        PayrollPeriod $payrollPeriod,
        RevertPayrollPeriodToDraft $revertPayrollPeriodToDraft,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        $this->assertUnrestrictedEmployeeScope($request, $companyId);

        $clearTimesheets = $request->boolean('clear_timesheets');
        $revertPayrollPeriodToDraft->handle($payrollPeriod, $clearTimesheets);

        $successMessage = match (true) {
            $payrollPeriod->isCrew() && $clearTimesheets => 'Pay period reverted to draft. Timesheets and payroll records were cleared.',
            $payrollPeriod->isCrew() => 'Pay period reverted to draft. Payroll records were cleared. Timesheets were kept.',
            default => 'Pay period reverted to draft. Payroll records were cleared.',
        };

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', $successMessage);
    }

    public function revertToApproved(
        RevertPayrollPeriodToApprovedRequest $request,
        PayrollPeriod $payrollPeriod,
        RevertPayrollPeriodToApproved $revertPayrollPeriodToApproved,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        $this->assertUnrestrictedEmployeeScope($request, $companyId);

        $revertPayrollPeriodToApproved->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Pay period reverted to approved. Payment status has been removed.');
    }

    public function revertToProcessing(
        RevertPayrollPeriodToProcessingRequest $request,
        PayrollPeriod $payrollPeriod,
        RevertPayrollPeriodToProcessing $revertPayrollPeriodToProcessing,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        $this->assertUnrestrictedEmployeeScope($request, $companyId);

        $revertPayrollPeriodToProcessing->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Pay period reverted to processing. Payslips and WPS data were cleared.');
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
        $this->assertUnrestrictedEmployeeScope($request, $companyId);

        $approvePayrollPeriod->handle($payrollPeriod, $user);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Pay period approved. Payslips are being generated in the background.');
    }

    public function markPaid(
        MarkPayrollPeriodPaidRequest $request,
        PayrollPeriod $payrollPeriod,
        MarkPayrollPeriodPaid $markPayrollPeriodPaid,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        $this->assertUnrestrictedEmployeeScope($request, $companyId);

        $proofFiles = $request->file('payment_proofs');
        if (! is_array($proofFiles) && $request->hasFile('payment_proof')) {
            $proofFiles = [$request->file('payment_proof')];
        }

        $markPayrollPeriodPaid->handle(
            $payrollPeriod,
            $proofFiles,
            $request->validated('payment_date'),
        );

        return redirect()
            ->route('payroll.show', $payrollPeriod)
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
        $this->assertUnrestrictedEmployeeScope($request, $companyId);

        $cancelPayrollPeriod->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Pay period cancelled.');
    }

    /**
     * @return array{crew: int, office: int, daily_crew: int}
     */
    private function employeeCountsByCategory(int $companyId, ?User $user = null): array
    {
        $crewQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);
        $officeQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Office);

        if ($user !== null) {
            EmployeeVisibilityScope::apply($crewQuery, $user, $companyId);
            EmployeeVisibilityScope::apply($officeQuery, $user, $companyId);
        }

        $dailyCrewQuery = Employee::query()
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->whereHas('currentContract', function ($contractQuery): void {
                $contractQuery->where('payroll_category', PayrollCategory::Crew);
                ContractSalaryStructureFilter::apply(
                    $contractQuery,
                    ContractSalaryStructureFilter::DAILY,
                );
            });

        if ($user !== null) {
            EmployeeVisibilityScope::apply($dailyCrewQuery, $user, $companyId);
        }

        return [
            PayrollCategory::Crew->value => $crewQuery->count(),
            PayrollCategory::Office->value => $officeQuery->count(),
            'daily_crew' => $dailyCrewQuery->count(),
        ];
    }

    private function assertUnrestrictedEmployeeScope(Request $request, int $companyId): void
    {
        abort_unless(
            EmployeeVisibilityScope::hasUnrestrictedAccess($request->user(), $companyId),
            403,
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function payrollCategoryOptions(bool $includeOffice = true): array
    {
        $categories = $includeOffice
            ? PayrollCategory::cases()
            : [PayrollCategory::Crew];

        return collect($categories)
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

    private function authorizePayrollShow(Request $request, PayrollPeriod $payrollPeriod): void
    {
        $user = $request->user();

        if ($user?->can('payroll.periods.view')) {
            return;
        }

        abort_unless(
            $payrollPeriod->isCrew() && ($user?->can('payroll.crew_timesheets.view') ?? false),
            403,
        );
    }
}
