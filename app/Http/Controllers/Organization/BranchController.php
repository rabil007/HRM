<?php

namespace App\Http\Controllers\Organization;

use App\Exports\BranchesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Branch\StoreBranchRequest;
use App\Http\Requests\Organization\Branch\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Country;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

class BranchController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'dial_code']);

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (Branch $branch) => [
                'id' => $branch->id,
                'company' => [
                    'id' => $branch->company_id,
                    'name' => null,
                ],
                'name' => $branch->name,
                'code' => $branch->code,
                'address' => $branch->address,
                'city' => $branch->city,
                'country' => $branch->country,
                'phone' => $branch->phone,
                'email' => $branch->email,
                'is_headquarters' => (bool) $branch->is_headquarters,
                'status' => $branch->status,
                'created_at' => $branch->created_at,
            ]);

        return Inertia::render('organization/branches', [
            'branches' => $branches,
            'countries' => $countries,
        ]);
    }

    public function show(Branch $branch)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $branch->company_id === $companyId, 404);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'dial_code']);

        $recentActivity = [];
        $request = request();
        if ($request->user()?->can('audit.view')) {
            $recentActivity = Activity::query()
                ->where('company_id', $companyId)
                ->where('subject_type', Branch::class)
                ->where('subject_id', $branch->id)
                ->with(['causer:id,name,email'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Activity $log) => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'description' => $log->description,
                    'causer' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                        'email' => $log->causer->email,
                    ] : null,
                    'old_values' => $log->attribute_changes?->get('old'),
                    'new_values' => $log->attribute_changes?->get('attributes'),
                    'created_at' => $log->created_at,
                ])
                ->all();
        }

        return Inertia::render('organization/branch', [
            'branch' => [
                'id' => $branch->id,
                'company' => [
                    'id' => $branch->company_id,
                    'name' => null,
                    'slug' => null,
                ],
                'name' => $branch->name,
                'code' => $branch->code,
                'address' => $branch->address,
                'city' => $branch->city,
                'country' => $branch->country,
                'phone' => $branch->phone,
                'email' => $branch->email,
                'is_headquarters' => (bool) $branch->is_headquarters,
                'status' => $branch->status,
                'created_at' => $branch->created_at,
                'updated_at' => $branch->updated_at,
            ],
            'countries' => $countries,
            'recent_activity' => $recentActivity,
        ]);
    }

    public function store(StoreBranchRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = (int) $request->attributes->get('current_company_id');

        foreach (['code', 'address', 'city', 'country', 'phone', 'email'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['is_headquarters'] = $data['is_headquarters'] ?? false;
        $data['status'] = $data['status'] ?? 'active';

        Branch::create($data);

        return redirect()->route('organization.branches');
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $branch->company_id === $companyId, 404);

        $data = $request->validated();
        $data['company_id'] = $companyId;

        foreach (['code', 'address', 'city', 'country', 'phone', 'email'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['is_headquarters'] = $data['is_headquarters'] ?? false;
        $data['status'] = $data['status'] ?? 'active';

        $branch->update($data);

        return redirect()->route('organization.branches');
    }

    public function destroy(Branch $branch)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $branch->company_id === $companyId, 404);

        $branch->delete();

        return redirect()->route('organization.branches');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = (int) $request->attributes->get('current_company_id');
        $country = trim((string) $request->query('country', ''));
        $status = trim((string) $request->query('status', ''));
        $city = trim((string) $request->query('city', ''));
        $headquartersOnly = filter_var($request->query('headquartersOnly', false), FILTER_VALIDATE_BOOL);
        $hasEmail = filter_var($request->query('hasEmail', false), FILTER_VALIDATE_BOOL);
        $hasPhone = filter_var($request->query('hasPhone', false), FILTER_VALIDATE_BOOL);

        $query = Branch::query()
            ->where('company_id', $companyId)
            ->latest('id');

        if ($country !== '') {
            $query->where('country', $country);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($city !== '') {
            $query->where('city', 'like', '%'.$city.'%');
        }

        if ($headquartersOnly) {
            $query->where('is_headquarters', true);
        }

        if ($hasEmail) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        if ($hasPhone) {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $export = new BranchesExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "branches_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $branches = $query->get();
            $pdf = Pdf::loadView('exports.branches', [
                'branches' => $branches,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
