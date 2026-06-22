<?php

namespace App\Http\Controllers\Organization;

use App\Enums\PayrollPeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\StorePayrollPeriodRequest;
use App\Models\PayrollPeriod;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\PayrollPeriodResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PayrollPeriodController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);

        $paginator = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->latest('start_date')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('organization/payroll-periods/index', [
            'periods' => collect($paginator->items())
                ->map(fn (PayrollPeriod $period) => PayrollPeriodResource::toArray($period))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'permissions' => [
                'create' => $request->user()?->can('payroll.periods.create') ?? false,
            ],
        ]);
    }

    public function store(StorePayrollPeriodRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        PayrollPeriod::query()->create([
            'company_id' => $companyId,
            'name' => $request->validated('name'),
            'start_date' => $request->validated('start_date'),
            'end_date' => $request->validated('end_date'),
            'payment_date' => $request->validated('payment_date'),
            'notes' => $request->validated('notes'),
            'status' => PayrollPeriodStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('organization.payroll-periods.index')
            ->with('success', 'Payroll period created.');
    }
}
