<?php

namespace App\Http\Controllers\Organization;

use App\Exports\PositionsExport;
use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Position\StorePositionRequest;
use App\Http\Requests\Organization\Position\UpdatePositionRequest;
use App\Http\Requests\Organization\Position\UpdatePositionStatusRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Positions\PositionAttachmentStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

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
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('grade', 'like', "%{$search}%")
                        ->orWhere('attachment_original_name', 'like', "%{$search}%");
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
            'description' => $position->description,
            'grade' => $position->grade,
            'min_salary' => $position->min_salary,
            'max_salary' => $position->max_salary,
            'status' => $position->status,
            'attachment' => $this->attachmentData($position),
            'created_at' => $position->created_at,
        ]);

        $treeDepartments = Department::query()
            ->where('company_id', $companyId)
            ->withCount(['employees as users_count'])
            ->orderBy('name')
            ->get(['id', 'parent_id', 'branch_id', 'name']);

        $treePositions = Position::query()
            ->where('company_id', $companyId)
            ->withCount(['employees as users_count'])
            ->orderBy('title')
            ->get(['id', 'department_id', 'title', 'status', 'grade']);

        return Inertia::render('organization/positions', [
            'positions' => $positions->items(),
            'tree_departments' => $treeDepartments,
            'tree_positions' => $treePositions,
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
                'description' => $position->description,
                'grade' => $position->grade,
                'min_salary' => $position->min_salary,
                'max_salary' => $position->max_salary,
                'status' => $position->status,
                'attachment' => $this->attachmentData($position),
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

    public function store(
        StorePositionRequest $request,
        PositionAttachmentStorage $attachmentStorage,
    ): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) $request->attributes->get('current_company_id');
        $attachment = $request->file('attachment');

        unset($data['attachment']);
        $data['company_id'] = $companyId;

        foreach (['description', 'grade', 'min_salary', 'max_salary'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $scopeAttributes = ['company_id' => $companyId];
        if (isset($data['department_id'])) {
            $scopeAttributes['department_id'] = $data['department_id'];
        }

        $existing = $this->findExistingQuickCreate(
            Position::class,
            'title',
            (string) $data['title'],
            $scopeAttributes,
        );

        $redirect = redirect()
            ->route('organization.positions')
            ->with('success', 'Position created successfully.');

        if ($existing !== null) {
            return $this->storeRedirectOrQuickCreateJson($request, $existing, $redirect, 'title');
        }

        $storedPath = null;
        $positionId = 0;

        try {
            $position = DB::transaction(function () use (
                $data,
                $attachment,
                $attachmentStorage,
                &$storedPath,
                &$positionId,
            ): Position {
                $position = Position::query()->create($data);
                $positionId = (int) $position->id;

                if ($attachment instanceof UploadedFile) {
                    $attributes = $attachmentStorage->store($position, $attachment);
                    $storedPath = $attributes['attachment_path'];
                    $position->update($attributes);
                }

                return $position;
            });
        } catch (Throwable $exception) {
            $attachmentStorage->delete($storedPath, $companyId, $positionId);

            throw $exception;
        }

        return $this->storeRedirectOrQuickCreateJson($request, $position, $redirect, 'title');
    }

    public function update(
        UpdatePositionRequest $request,
        Position $position,
        PositionAttachmentStorage $attachmentStorage,
    ): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $data = $request->validated();
        $attachment = $request->file('attachment');
        $removeAttachment = $request->boolean('remove_attachment');

        unset($data['attachment'], $data['remove_attachment']);
        $data['company_id'] = $companyId;

        foreach (['description', 'grade', 'min_salary', 'max_salary'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $previousPath = null;
        $storedPath = null;

        try {
            DB::transaction(function () use (
                $position,
                $companyId,
                $data,
                $attachment,
                $removeAttachment,
                $attachmentStorage,
                &$previousPath,
                &$storedPath,
            ): void {
                $lockedPosition = Position::query()
                    ->where('company_id', $companyId)
                    ->whereKey($position->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $previousPath = $lockedPosition->attachment_path;

                if ($attachment instanceof UploadedFile) {
                    $attributes = $attachmentStorage->store($lockedPosition, $attachment);
                    $storedPath = $attributes['attachment_path'];
                    $data = [...$data, ...$attributes];
                } elseif ($removeAttachment) {
                    $data = [
                        ...$data,
                        'attachment_path' => null,
                        'attachment_original_name' => null,
                        'attachment_mime_type' => null,
                        'attachment_size_bytes' => null,
                        'attachment_checksum' => null,
                    ];
                }

                $lockedPosition->update($data);
            });
        } catch (Throwable $exception) {
            $attachmentStorage->delete($storedPath, $companyId, (int) $position->id);

            throw $exception;
        }

        if (($attachment instanceof UploadedFile || $removeAttachment) && $previousPath !== $storedPath) {
            $attachmentStorage->delete($previousPath, $companyId, (int) $position->id);
        }

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
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhere('attachment_original_name', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('department', fn ($dq) => $dq->where('name', 'like', "%{$search}%"));
            });
        }

        $companyName = Company::query()->whereKey($companyId)->value('name');
        $export = new PositionsExport($query, $companyName);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "positions_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $positions = $query->get();
            $pdf = Pdf::loadView('exports.positions', [
                'positions' => $positions,
                'companyName' => $companyName,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *     original_name: string,
     *     mime_type: string|null,
     *     size_bytes: int|null,
     *     is_image: bool,
     *     preview_url: string,
     *     download_url: string
     * }|null
     */
    private function attachmentData(Position $position): ?array
    {
        if ($position->attachment_path === null || $position->attachment_original_name === null) {
            return null;
        }

        return [
            'original_name' => $position->attachment_original_name,
            'mime_type' => $position->attachment_mime_type,
            'size_bytes' => $position->attachment_size_bytes !== null
                ? (int) $position->attachment_size_bytes
                : null,
            'is_image' => str_starts_with((string) $position->attachment_mime_type, 'image/'),
            'preview_url' => route('organization.positions.attachment.preview', $position),
            'download_url' => route('organization.positions.attachment.download', $position),
        ];
    }
}
