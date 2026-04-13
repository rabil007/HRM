<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Branch\StoreBranchRequest;
use App\Http\Requests\Organization\Branch\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function index()
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'dial_code']);

        $branches = Branch::query()
            ->with(['company:id,name'])
            ->latest('id')
            ->paginate(20)
            ->through(fn (Branch $branch) => [
                'id' => $branch->id,
                'company' => [
                    'id' => $branch->company_id,
                    'name' => $branch->company?->name,
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
            'companies' => $companies,
            'countries' => $countries,
        ]);
    }

    public function show(Branch $branch)
    {
        $branch->load(['company:id,name,slug']);

        return Inertia::render('organization/branch', [
            'branch' => [
                'id' => $branch->id,
                'company' => [
                    'id' => $branch->company_id,
                    'name' => $branch->company?->name,
                    'slug' => $branch->company?->slug,
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
        ]);
    }

    public function store(StoreBranchRequest $request)
    {
        $data = $request->validated();

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
        $data = $request->validated();

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
        $branch->delete();

        return redirect()->route('organization.branches');
    }
}
