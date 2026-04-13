<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Company\StoreCompanyRequest;
use App\Http\Requests\Organization\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
        /** @var FilesystemAdapter $publicDisk */
        $publicDisk = Storage::disk('public');

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'dial_code']);

        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'symbol']);

        $companies = Company::query()
            ->with(['currency:id,code', 'country:id,code,name'])
            ->latest('id')
            ->paginate(12)
            ->through(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'logo_url' => $company->logo ? $publicDisk->url($company->logo) : null,
                'industry' => $company->industry,
                'city' => $company->city,
                'country' => [
                    'id' => $company->country_id,
                    'code' => $company->country?->code,
                    'name' => $company->country?->name,
                ],
                'company_size' => $company->company_size,
                'registration_number' => $company->registration_number,
                'tax_id' => $company->tax_id,
                'address' => $company->address,
                'phone' => $company->phone,
                'email' => $company->email,
                'website' => $company->website,
                'currency' => [
                    'id' => $company->currency_id,
                    'code' => $company->currency?->code,
                ],
                'timezone' => $company->timezone,
                'fiscal_year_start' => $company->fiscal_year_start,
                'payroll_cycle' => $company->payroll_cycle,
                'working_days' => $company->working_days,
                'wps_agent_code' => $company->wps_agent_code,
                'wps_mol_uid' => $company->wps_mol_uid,
                'status' => $company->status,
                'created_at' => $company->created_at,
            ]);

        return Inertia::render('organization/companies', [
            'companies' => $companies,
            'countries' => $countries,
            'currencies' => $currencies,
        ]);
    }

    public function show(Company $company)
    {
        /** @var FilesystemAdapter $publicDisk */
        $publicDisk = Storage::disk('public');

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'dial_code']);

        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'symbol']);

        $company->load([
            'country:id,code,name,dial_code',
            'currency:id,code,name,symbol',
            'branches:id,company_id,name,code,address,city,country,phone,email,is_headquarters,status,created_at',
        ]);

        return Inertia::render('organization/company', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'logo_url' => $company->logo ? $publicDisk->url($company->logo) : null,
                'industry' => $company->industry,
                'company_size' => $company->company_size,
                'registration_number' => $company->registration_number,
                'tax_id' => $company->tax_id,
                'country' => [
                    'id' => $company->country_id,
                    'code' => $company->country?->code,
                    'name' => $company->country?->name,
                    'dial_code' => $company->country?->dial_code,
                ],
                'city' => $company->city,
                'address' => $company->address,
                'phone' => $company->phone,
                'email' => $company->email,
                'website' => $company->website,
                'currency' => [
                    'id' => $company->currency_id,
                    'code' => $company->currency?->code,
                    'name' => $company->currency?->name,
                    'symbol' => $company->currency?->symbol,
                ],
                'timezone' => $company->timezone,
                'fiscal_year_start' => $company->fiscal_year_start,
                'payroll_cycle' => $company->payroll_cycle,
                'working_days' => $company->working_days,
                'wps_agent_code' => $company->wps_agent_code,
                'wps_mol_uid' => $company->wps_mol_uid,
                'status' => $company->status,
                'created_at' => $company->created_at,
                'updated_at' => $company->updated_at,
            ],
            'branches' => $company->branches
                ->sortByDesc('id')
                ->values()
                ->map(fn (mixed $branch) => [
                    'id' => $branch->id,
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
                ]),
            'countries' => $countries,
            'currencies' => $currencies,
        ]);
    }

    public function store(StoreCompanyRequest $request)
    {
        $data = $request->validated();

        foreach ([
            'industry',
            'company_size',
            'registration_number',
            'tax_id',
            'city',
            'address',
            'phone',
            'email',
            'website',
            'timezone',
            'fiscal_year_start',
            'payroll_cycle',
            'wps_agent_code',
            'wps_mol_uid',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['working_days'] = $data['working_days'] ?? [1, 2, 3, 4, 5];

        $data['country_id'] = $data['country_id'] ?? Country::query()->where('code', 'UAE')->value('id');
        $data['currency_id'] = $data['currency_id'] ?? Currency::query()->where('code', 'AED')->value('id');
        $data['timezone'] = $data['timezone'] ?? 'Asia/Dubai';
        $data['fiscal_year_start'] = $data['fiscal_year_start'] ?? '01-01';
        $data['payroll_cycle'] = $data['payroll_cycle'] ?? 'monthly';
        $data['status'] = $data['status'] ?? 'active';

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('company-logos', 'public');
        }

        Company::create($data);

        return redirect()->route('organization.companies');
    }

    public function update(UpdateCompanyRequest $request, Company $company)
    {
        $data = $request->validated();

        foreach ([
            'industry',
            'company_size',
            'registration_number',
            'tax_id',
            'city',
            'address',
            'phone',
            'email',
            'website',
            'timezone',
            'fiscal_year_start',
            'payroll_cycle',
            'wps_agent_code',
            'wps_mol_uid',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['working_days'] = $data['working_days'] ?? $company->working_days ?? [1, 2, 3, 4, 5];

        if ($request->hasFile('logo')) {
            if ($company->logo) {
                Storage::disk('public')->delete($company->logo);
            }

            $data['logo'] = $request->file('logo')->store('company-logos', 'public');
        }

        $company->update($data);

        return redirect()->route('organization.companies');
    }

    public function destroy(Company $company)
    {
        $company->delete();

        return redirect()->route('organization.companies');
    }
}
