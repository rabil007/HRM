<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\UpsertCrewTimesheetRequest;
use App\Models\PayrollPeriod;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewPayrollPagePermissions;
use App\Support\Payroll\CrewTimesheetBoardQuery;
use App\Support\Payroll\PayrollPeriodResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CrewPayrollController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request, CrewTimesheetBoardQuery $boardQuery): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));

        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->latest('start_date')
            ->get()
            ->map(fn (PayrollPeriod $period) => PayrollPeriodResource::toArray($period));

        $periodId = $request->integer('period_id') ?: null;

        if ($periodId === null && $periods->isNotEmpty()) {
            $periodId = (int) $periods->first()['id'];
        }

        $selectedPeriod = $periodId !== null
            ? PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->whereKey($periodId)
                ->first()
            : null;

        if ($selectedPeriod === null && $periodId !== null) {
            abort(404);
        }

        $rows = collect();
        $pagination = [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];

        if ($selectedPeriod !== null) {
            $paginator = $boardQuery->paginate(
                companyId: $companyId,
                periodId: $selectedPeriod->id,
                search: $search !== '' ? $search : null,
                perPage: $perPage,
            );

            $rows = collect($paginator->items());
            $pagination = $this->paginationMeta($paginator);
        }

        return Inertia::render('organization/crew-payroll/index', [
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod !== null
                ? PayrollPeriodResource::toArray($selectedPeriod)
                : null,
            'rows' => $rows,
            'pagination' => $pagination,
            'search' => $search,
            'permissions' => CrewPayrollPagePermissions::for($request->user()),
        ]);
    }

    public function storeTimesheet(
        UpsertCrewTimesheetRequest $request,
        UpsertCrewTimesheet $upsertCrewTimesheet,
    ): RedirectResponse {
        $upsertCrewTimesheet->handle(
            $request->period(),
            $request->employee(),
            $request->timesheetData(),
        );

        return redirect()
            ->route('organization.crew-payroll.index', [
                'period_id' => $request->validated('period_id'),
            ])
            ->with('success', 'Crew timesheet saved.');
    }
}
