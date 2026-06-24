<?php

namespace App\Http\Controllers\Organization;

use App\Exports\PositionsExport;
use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Position\StorePositionRequest;
use App\Http\Requests\Organization\Position\UpdatePositionRequest;
use App\Http\Requests\Organization\Position\UpdatePositionStatusRequest;
use App\Models\Department;
use App\Models\Position;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Pagination\ResolvesPerPage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class PositionController extends Controller
{
    use ResolvesPerPage;
    use ReturnsQuickCreateJson;

    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $search = trim((string) request()->query('search', ''));
        $departmentId = trim((string) request()->query('department_id', ''));
        $status = trim((string) request()->query('status', ''));
        $grade = trim((string) request()->query('grade', ''));

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $paginator = Position::query()
            ->with([
                'department:id,name',
            ])
            ->where('company_id', $companyId)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($grade, fn ($q) => $q->where('grade', 'like', "%{$grade}%"))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('grade', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $positions = $paginator->through(fn (Position $position) => [
            'id' => $position->id,
            'company' => [
                'id' => $position->company_id,
                'name' => null,
            ],
            'department' => $position->department_id ? [
                'id' => $position->department_id,
                'name' => $position->department?->name,
            ] : null,
            'title' => $position->title,
            'grade' => $position->grade,
            'min_salary' => $position->min_salary,
            'max_salary' => $position->max_salary,
            'status' => $position->status,
            'created_at' => $position->created_at,
        ]);

        return Inertia::render('organization/positions', [
            'positions' => $positions->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'department_id' => $departmentId,
                'status' => $status,
                'grade' => $grade,
            ],
            'departments' => $departments,
        ]);
    }

    public function show(Position $position)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $position->load([
            'department.parent:id,name',
            'department:id,name,parent_id',
        ]);

        $position->loadCount([
            'employees as users_count' => fn ($q) => $q->where('company_id', $companyId),
        ]);

        $request = request();

        return Inertia::render('organization/position', [
            'position' => [
                'id' => $position->id,
                'company' => [
                    'id' => $position->company_id,
                    'name' => null,
                    'slug' => null,
                ],
                'department' => $position->department_id ? [
                    'id' => $position->department_id,
                    'name' => $position->department?->name,
                    'parent' => $position->department?->parent_id ? [
                        'id' => $position->department->parent_id,
                        'name' => $position->department->parent?->name,
                    ] : null,
                ] : null,
                'users_count' => $position->users_count,
                'title' => $position->title,
                'grade' => $position->grade,
                'min_salary' => $position->min_salary,
                'max_salary' => $position->max_salary,
                'status' => $position->status,
                'created_at' => $position->created_at,
                'updated_at' => $position->updated_at,
            ],
            'departments' => $departments,
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                Position::class,
                $position->id,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }

    public function store(StorePositionRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) $request->attributes->get('current_company_id');
        $data['company_id'] = $companyId;

        foreach (['grade', 'min_salary', 'max_salary'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $scopeAttributes = ['company_id' => $companyId];
        if (isset($data['department_id'])) {
            $scopeAttributes['department_id'] = $data['department_id'];
        }

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Position::class,
            $data,
            redirect()
                ->route('organization.positions')
                ->with('success', 'Position created successfully.'),
            'title',
            $scopeAttributes,
        );
    }

    public function update(UpdatePositionRequest $request, Position $position)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $data = $request->validated();
        $data['company_id'] = $companyId;

        foreach (['grade', 'min_salary', 'max_salary'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $position->update($data);

        return redirect()
            ->route('organization.positions')
            ->with('success', 'Position updated successfully.');
    }

    public function destroy(Position $position)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $position->delete();

        return redirect()
            ->route('organization.positions')
            ->with('success', 'Position deleted successfully.');
    }

    public function updateStatus(UpdatePositionStatusRequest $request, Position $position)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $position->update([
            'status' => $request->validated('status'),
        ]);

        return redirect()
            ->route('organization.positions')
            ->with('success', 'Position status updated successfully.');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = (int) $request->attributes->get('current_company_id');
        $departmentId = trim((string) $request->query('department_id', ''));
        $status = trim((string) $request->query('status', ''));
        $grade = trim((string) $request->query('grade', ''));

        $query = Position::query()
            ->with(['department:id,name'])
            ->where('company_id', $companyId)
            ->latest('id');

        if ($departmentId !== '') {
            $query->where('department_id', $departmentId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($grade !== '') {
            $query->where('grade', 'like', "%{$grade}%");
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('department', fn ($dq) => $dq->where('name', 'like', "%{$search}%"));
            });
        }

        $export = new PositionsExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "positions_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $positions = $query->get();
            $pdf = Pdf::loadView('exports.positions', [
                'positions' => $positions,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
