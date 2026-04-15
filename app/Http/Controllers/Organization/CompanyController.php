<?php

namespace App\Http\Controllers\Organization;

use App\Exports\CompaniesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Company\StoreCompanyRequest;
use App\Http\Requests\Organization\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

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

        $companyId = (int) request()->attributes->get('current_company_id');

        $recentActivity = Activity::query()
            ->where('company_id', $companyId)
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
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
                'payroll_cycle' => $company->payroll_cycle,
                'working_days' => $company->working_days,
                'wps_agent_code' => $company->wps_agent_code,
                'wps_mol_uid' => $company->wps_mol_uid,
                'status' => $company->status,
                'created_at' => $company->created_at,
                'updated_at' => $company->updated_at,
            ],
            'recent_activity' => $recentActivity,
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
        $data['payroll_cycle'] = $data['payroll_cycle'] ?? 'monthly';
        $data['status'] = $data['status'] ?? 'active';

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('company-logos', 'public');
        }

        $company = Company::create($data);

        $user = $request->user();
        if ($user) {
            $user->companies()->syncWithoutDetaching([
                $company->id => ['status' => 'active'],
            ]);

            $role = SpatieRole::query()->firstOrCreate([
                'company_id' => $company->id,
                'name' => 'Owner',
                'guard_name' => 'web',
            ]);

            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->pluck('name')
                ->all();

            $role->syncPermissions($permissions);

            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            $user->assignRole($role);
        }

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

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $industry = trim((string) $request->query('industry', ''));
        $country = trim((string) $request->query('country', ''));
        $currency = trim((string) $request->query('currency', ''));
        $hasLogo = filter_var($request->query('hasLogo', false), FILTER_VALIDATE_BOOL);
        $hasEmail = filter_var($request->query('hasEmail', false), FILTER_VALIDATE_BOOL);
        $hasWebsite = filter_var($request->query('hasWebsite', false), FILTER_VALIDATE_BOOL);

        $query = Company::query()
            ->with(['country:id,code,name', 'currency:id,code'])
            ->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('industry', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhereHas('country', function ($cq) use ($search) {
                        $cq->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($industry !== '') {
            $query->where('industry', 'like', "%{$industry}%");
        }

        if ($country !== '') {
            $query->whereHas('country', function ($cq) use ($country) {
                $cq->where('code', 'like', "%{$country}%")
                    ->orWhere('name', 'like', "%{$country}%");
            });
        }

        if ($currency !== '') {
            $query->whereHas('currency', fn ($cq) => $cq->where('code', $currency));
        }

        if ($hasLogo) {
            $query->whereNotNull('logo');
        }

        if ($hasEmail) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        if ($hasWebsite) {
            $query->whereNotNull('website')->where('website', '!=', '');
        }

        $export = new CompaniesExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "companies_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $companies = $query->get();
            $pdf = Pdf::loadView('exports.companies', [
                'companies' => $companies,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
