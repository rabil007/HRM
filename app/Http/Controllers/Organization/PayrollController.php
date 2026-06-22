<?php

namespace App\Http\Controllers\Organization;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\StorePayrollPeriodRequest;
use App\Http\Requests\Organization\Payroll\UpsertCrewTimesheetRequest;
use App\Models\PayrollPeriod;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewPayrollPagePermissions;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollPeriodBoardQuery;
use App\Support\Payroll\PayrollPeriodListResource;
use App\Support\Payroll\PayrollPeriodResource;
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

        $paginator = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->withCount('crewTimesheets')
            ->latest('start_date')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('organization/payroll/index', [
            'periods' => collect($paginator->items())
                ->map(fn (PayrollPeriod $period) => PayrollPeriodListResource::toArray($period, $employeeCountsByCategory))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'payroll_categories' => $this->payrollCategoryOptions(),
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

        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $payrollCategory = $payrollPeriod->payroll_category ?? PayrollCategory::Crew;

        $paginator = $boardQuery->paginate(
            companyId: $companyId,
            periodId: $payrollPeriod->id,
            payrollCategory: $payrollCategory,
            search: $search !== '' ? $search : null,
            perPage: $perPage,
        );

        return Inertia::render('organization/payroll/show', [
            'period' => PayrollPeriodResource::toArray($payrollPeriod),
            'rows' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
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
            ->route('organization.payroll.index')
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
            ->route('organization.payroll.show', $payrollPeriod)
            ->with('success', 'Crew timesheet saved.');
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
