<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryAdjustmentStatus;
use App\Enums\SalaryAdjustmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ApproveSalaryAdjustmentRequest;
use App\Http\Requests\Organization\Payroll\RejectSalaryAdjustmentRequest;
use App\Http\Requests\Organization\Payroll\StoreSalaryAdjustmentRequest;
use App\Http\Requests\Organization\Payroll\UpdateSalaryAdjustmentRequest;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryAdjustment;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\SalaryAdjustmentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalaryAdjustmentController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $type = trim((string) $request->query('type', ''));
        $employeeId = trim((string) $request->query('employee_id', ''));

        $query = SalaryAdjustment::query()
            ->where('company_id', $companyId)
            ->with(['employee', 'period', 'approver'])
            ->orderByDesc('id');

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(reason) LIKE ?', [$term])
                    ->orWhereHas('employee', function (Builder $employeeQuery) use ($term): void {
                        $employeeQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(employee_no) LIKE ?', [$term]);
                    });
            });
        }

        if (in_array($status, SalaryAdjustmentStatus::values(), true)) {
            $query->where('status', $status);
        }

        if (in_array($type, SalaryAdjustmentType::values(), true)) {
            $query->where('type', $type);
        }

        if ($employeeId !== '' && ctype_digit($employeeId)) {
            $query->where('employee_id', (int) $employeeId);
        }

        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name']);

        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [PayrollPeriodStatus::Draft, PayrollPeriodStatus::Processing])
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date']);

        $user = $request->user();

        return Inertia::render('payroll/adjustments', [
            'adjustments' => collect($paginator->items())
                ->map(fn (SalaryAdjustment $adjustment) => SalaryAdjustmentResource::toArray($adjustment))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'status' => $status,
                'type' => $type,
                'employee_id' => $employeeId,
            ],
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->name,
            ])->values()->all(),
            'periods' => $periods->map(fn (PayrollPeriod $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
            ])->values()->all(),
            'type_options' => collect(SalaryAdjustmentType::cases())
                ->map(fn (SalaryAdjustmentType $item) => ['value' => $item->value, 'label' => $item->label()])
                ->values()
                ->all(),
            'status_options' => collect(SalaryAdjustmentStatus::cases())
                ->map(fn (SalaryAdjustmentStatus $item) => ['value' => $item->value, 'label' => $item->label()])
                ->values()
                ->all(),
            'can' => [
                'create' => $user?->can('payroll.adjustments.create') ?? false,
                'update' => $user?->can('payroll.adjustments.update') ?? false,
                'delete' => $user?->can('payroll.adjustments.delete') ?? false,
                'approve' => $user?->can('payroll.adjustments.approve') ?? false,
            ],
        ]);
    }

    public function store(StoreSalaryAdjustmentRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        SalaryAdjustment::query()->create([
            'company_id' => $companyId,
            'employee_id' => $request->employee()->id,
            'period_id' => $request->period()?->id,
            'type' => $request->validated('type'),
            'amount' => $request->validated('amount'),
            'reason' => $request->validated('reason'),
            'status' => SalaryAdjustmentStatus::Pending,
        ]);

        return redirect()
            ->route('payroll.adjustments.index')
            ->with('success', 'Salary adjustment created.');
    }

    public function update(UpdateSalaryAdjustmentRequest $request, SalaryAdjustment $salaryAdjustment): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $salaryAdjustment->company_id === $companyId, 404);

        if (! $salaryAdjustment->isPending()) {
            return redirect()
                ->route('payroll.adjustments.index')
                ->withErrors(['salary_adjustment' => 'Only pending adjustments can be edited.']);
        }

        $salaryAdjustment->update([
            'employee_id' => (int) $request->validated('employee_id'),
            'period_id' => $request->period()?->id,
            'type' => $request->validated('type'),
            'amount' => $request->validated('amount'),
            'reason' => $request->validated('reason'),
        ]);

        return redirect()
            ->route('payroll.adjustments.index')
            ->with('success', 'Salary adjustment updated.');
    }

    public function destroy(Request $request, SalaryAdjustment $salaryAdjustment): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can('payroll.adjustments.delete'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $salaryAdjustment->company_id === $companyId, 404);

        if (! $salaryAdjustment->isPending()) {
            return redirect()
                ->route('payroll.adjustments.index')
                ->withErrors(['salary_adjustment' => 'Only pending adjustments can be deleted.']);
        }

        $salaryAdjustment->delete();

        return redirect()
            ->route('payroll.adjustments.index')
            ->with('success', 'Salary adjustment deleted.');
    }

    public function approve(ApproveSalaryAdjustmentRequest $request, SalaryAdjustment $salaryAdjustment): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $salaryAdjustment->company_id === $companyId, 404);

        if (! $salaryAdjustment->isPending()) {
            return redirect()
                ->route('payroll.adjustments.index')
                ->withErrors(['salary_adjustment' => 'Only pending adjustments can be approved.']);
        }

        $salaryAdjustment->update([
            'status' => SalaryAdjustmentStatus::Approved,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return redirect()
            ->route('payroll.adjustments.index')
            ->with('success', 'Salary adjustment approved.');
    }

    public function reject(RejectSalaryAdjustmentRequest $request, SalaryAdjustment $salaryAdjustment): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $salaryAdjustment->company_id === $companyId, 404);

        if (! $salaryAdjustment->isPending()) {
            return redirect()
                ->route('payroll.adjustments.index')
                ->withErrors(['salary_adjustment' => 'Only pending adjustments can be rejected.']);
        }

        $salaryAdjustment->update([
            'status' => SalaryAdjustmentStatus::Rejected,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'rejection_reason' => $request->validated('rejection_reason'),
        ]);

        return redirect()
            ->route('payroll.adjustments.index')
            ->with('success', 'Salary adjustment rejected.');
    }
}
