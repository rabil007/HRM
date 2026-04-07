<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Company\StoreCompanyRequest;
use App\Http\Requests\Organization\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
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
                'logo_url' => $company->logo ? Storage::disk('public')->url($company->logo) : null,
                'industry' => $company->industry,
                'city' => $company->city,
                'country' => [
                    'id' => $company->country_id,
                    'code' => $company->country?->code,
                    'name' => $company->country?->name,
                ],
                'email' => $company->email,
                'website' => $company->website,
                'currency' => [
                    'id' => $company->currency_id,
                    'code' => $company->currency?->code,
                ],
                'created_at' => $company->created_at,
            ]);

        return Inertia::render('organization/companies', [
            'companies' => $companies,
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
