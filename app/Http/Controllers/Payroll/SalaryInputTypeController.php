<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\StoreSalaryInputTypeRequest;
use App\Http\Requests\Organization\Payroll\UpdateSalaryInputTypeRequest;
use App\Http\Requests\Organization\Payroll\UpdateSalaryInputTypeStatusRequest;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalaryInputTypeController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private ProvisionDefaultSalaryInputTypes $provisionDefaultSalaryInputTypes,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()?->can('payroll.salary_inputs.view')
            || $request->user()?->can('payroll.periods.update'),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');
        $this->provisionDefaultSalaryInputTypes->handle($companyId);

        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));

        $paginator = SalaryInputType::query()
            ->where('company_id', $companyId)
            ->withCount('salaryInputs')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $salaryInputTypes = $paginator->through(fn (SalaryInputType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'is_addition' => $type->is_addition,
            'status' => $type->status,
            'salary_inputs_count' => $type->salary_inputs_count,
        ]);

        return Inertia::render('payroll/salary-inputs', [
            'salary_input_types' => $salaryInputTypes->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
        ]);
    }

    public function store(StoreSalaryInputTypeRequest $request): RedirectResponse
    {
        $data = $this->normalizeTypeData($request->validated());
        $data['company_id'] = (int) $request->attributes->get('current_company_id');

        SalaryInputType::query()->create($data);

        return redirect()
            ->route('payroll.salary-inputs.index')
            ->with('success', 'Salary input type created successfully.');
    }

    public function update(UpdateSalaryInputTypeRequest $request, SalaryInputType $salaryInputType): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $salaryInputType->company_id === $companyId, 404);

        $salaryInputType->update($this->normalizeTypeData($request->validated()));

        return redirect()
            ->route('payroll.salary-inputs.index')
            ->with('success', 'Salary input type updated successfully.');
    }

    public function updateStatus(UpdateSalaryInputTypeStatusRequest $request, SalaryInputType $salaryInputType): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $salaryInputType->company_id === $companyId, 404);

        $salaryInputType->update([
            'status' => $request->validated('status'),
        ]);

        return redirect()
            ->route('payroll.salary-inputs.index')
            ->with('success', 'Salary input type status updated successfully.');
    }

    public function destroy(SalaryInputType $salaryInputType): RedirectResponse
    {
        abort_unless(
            request()->user()?->can('payroll.salary_inputs.delete')
            || request()->user()?->can('payroll.periods.update'),
            403,
        );

        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $salaryInputType->company_id === $companyId, 404);

        if (SalaryInput::query()->where('salary_input_type_id', $salaryInputType->id)->exists()) {
            return redirect()
                ->route('payroll.salary-inputs.index')
                ->withErrors(['salary_input_type' => 'This type cannot be deleted because it is used in pay runs.']);
        }

        $salaryInputType->delete();

        return redirect()
            ->route('payroll.salary-inputs.index')
            ->with('success', 'Salary input type deleted successfully.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTypeData(array $data): array
    {
        $data['is_addition'] = (bool) ($data['is_addition'] ?? false);
        $data['code'] = strtolower((string) $data['code']);

        return $data;
    }
}
