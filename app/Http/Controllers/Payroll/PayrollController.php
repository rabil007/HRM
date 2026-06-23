<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ApprovePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\CancelPayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\GenerateCrewPayrollRequest;
use App\Http\Requests\Organization\Payroll\MarkPayrollPeriodPaidRequest;
use App\Http\Requests\Organization\Payroll\RevertPayrollPeriodToDraftRequest;
use App\Http\Requests\Organization\Payroll\StorePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\UpsertCrewTimesheetRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\Actions\ApprovePayrollPeriod;
use App\Support\Payroll\Actions\CancelPayrollPeriod;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\MarkPayrollPeriodPaid;
use App\Support\Payroll\Actions\RevertPayrollPeriodToDraft;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewPayrollPagePermissions;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollHubSummary;
use App\Support\Payroll\PayrollPeriodBoardQuery;
use App\Support\Payroll\PayrollPeriodBoardSummary;
use App\Support\Payroll\PayrollPeriodListResource;
use App\Support\Payroll\PayrollPeriodResource;
use App\Support\Payroll\PayrollRecordResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PayrollController extends Controller
{
    use ResolvesPerPage;

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
    ): InertiaResponse {
        $this->authorizePayrollShow($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $payrollPeriod->load('approvedBy')->loadCount('payrollRecords');

        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $tab = trim((string) $request->query('tab', 'timesheets'));
        $payrollCategory = $payrollPeriod->payroll_category ?? PayrollCategory::Crew;

        $paginator = $boardQuery->paginate(
            companyId: $companyId,
            periodId: $payrollPeriod->id,
            payrollCategory: $payrollCategory,
            search: $search !== '' ? $search : null,
            perPage: $perPage,
        );

        $payrollRecords = [];
        $payrollRecordsPagination = null;

        if ($payrollPeriod->isCrew()) {
            $recordsPaginator = PayrollRecord::query()
                ->where('company_id', $companyId)
                ->where('period_id', $payrollPeriod->id)
                ->with('employee')
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'records_page')
                ->withQueryString();

            $payrollRecords = collect($recordsPaginator->items())
                ->map(fn (PayrollRecord $record) => PayrollRecordResource::toArray($record))
                ->values()
                ->all();
            $payrollRecordsPagination = $this->paginationMeta($recordsPaginator);
        }

        return Inertia::render('payroll/show', [
            'period' => PayrollPeriodResource::toArray($payrollPeriod),
            'rows' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'board_summary' => PayrollPeriodBoardSummary::forPeriod($payrollPeriod, (int) $paginator->total()),
            'payroll_records' => $payrollRecords,
            'payroll_records_pagination' => $payrollRecordsPagination,
            'tab' => in_array($tab, ['timesheets', 'payroll'], true) ? $tab : 'timesheets',
            'generation_summary' => $request->session()->get('crew_payroll_generation'),
            'search' => $search,
            'permissions' => CrewPayrollPagePermissions::for($request->user()),
            'timesheet_draft' => $payrollPeriod->isCrew()
                ? $this->timesheetDraftFromOldInput($request)
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
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
            'overtime_amount' => $request->old('overtime_amount'),
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

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Crew timesheet saved.');
    }

    public function generateCrewPayroll(
        GenerateCrewPayrollRequest $request,
        PayrollPeriod $payrollPeriod,
        GenerateCrewPayroll $generateCrewPayroll,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless($payrollPeriod->isCrew(), 404);

        $result = $generateCrewPayroll->handle($payrollPeriod);

        $message = $result->generatedCount > 0
            ? "Generated payroll for {$result->generatedCount} employee(s)."
            : 'No payroll records were generated.';

        if ($result->skippedCount > 0) {
            $message .= " {$result->skippedCount} employee(s) skipped (no timesheet).";
        }

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ])
            ->with('success', $message)
            ->with('crew_payroll_generation', $result->toSessionArray());
    }

    public function revertToDraft(
        RevertPayrollPeriodToDraftRequest $request,
        PayrollPeriod $payrollPeriod,
        RevertPayrollPeriodToDraft $revertPayrollPeriodToDraft,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $revertPayrollPeriodToDraft->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'timesheets',
            ])
            ->with('success', 'Pay period reverted to draft. Timesheets can be edited again.');
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
            ->with('success', 'Pay period approved.');
    }

    public function markPaid(
        MarkPayrollPeriodPaidRequest $request,
        PayrollPeriod $payrollPeriod,
        MarkPayrollPeriodPaid $markPayrollPeriodPaid,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $markPayrollPeriodPaid->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', [
                'payrollPeriod' => $payrollPeriod,
                'tab' => 'payroll',
            ])
            ->with('success', 'Pay period marked as paid.');
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
