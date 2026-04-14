<?php

namespace App\Http\Controllers\Organization;

use App\Exports\PositionsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Position\StorePositionRequest;
use App\Http\Requests\Organization\Position\UpdatePositionRequest;
use App\Models\Department;
use App\Models\Position;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class PositionController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $positions = Position::query()
            ->with([
                'department:id,name',
            ])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (Position $position) => [
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
            'positions' => $positions,
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
            'department:id,name',
        ]);

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
                ] : null,
                'title' => $position->title,
                'grade' => $position->grade,
                'min_salary' => $position->min_salary,
                'max_salary' => $position->max_salary,
                'status' => $position->status,
                'created_at' => $position->created_at,
                'updated_at' => $position->updated_at,
            ],
            'departments' => $departments,
        ]);
    }

    public function store(StorePositionRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = (int) $request->attributes->get('current_company_id');

        foreach (['grade', 'min_salary', 'max_salary'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        Position::create($data);

        return redirect()->route('organization.positions');
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

        return redirect()->route('organization.positions');
    }

    public function destroy(Position $position)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $position->delete();

        return redirect()->route('organization.positions');
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
