<?php

namespace App\Http\Controllers\Organization;

use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\User\StoreUserRequest;
use App\Http\Requests\Organization\User\UpdateUserRequest;
use App\Models\Company;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index()
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $users = User::query()
            ->with(['company:id,name'])
            ->latest('id')
            ->paginate(20)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'company' => $user->company_id ? [
                    'id' => $user->company_id,
                    'name' => $user->company?->name,
                ] : null,
                'role' => null,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('organization/users', [
            'users' => $users,
            'companies' => $companies,
        ]);
    }

    public function show(User $user)
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $user->load([
            'company:id,name,slug',
        ]);

        $memberships = $user->companies()
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.name', 'company_user.status'])
            ->map(function (Company $company) use ($user) {
                $roleNames = DB::table('spatie_model_has_roles')
                    ->join('spatie_roles', 'spatie_roles.id', '=', 'spatie_model_has_roles.role_id')
                    ->where('spatie_model_has_roles.model_type', $user::class)
                    ->where('spatie_model_has_roles.model_id', $user->id)
                    ->where('spatie_model_has_roles.company_id', $company->id)
                    ->orderBy('spatie_roles.name')
                    ->pluck('spatie_roles.name')
                    ->all();

                return [
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->name,
                    ],
                    'status' => (string) ($company->pivot?->status ?? 'active'),
                    'roles' => $roleNames,
                ];
            })
            ->values()
            ->all();

        $availableCompanies = Company::query()
            ->whereNotIn('id', $user->companies()->pluck('companies.id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $spatieRoles = SpatieRole::query()
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        return Inertia::render('organization/user', [
            'user' => [
                'id' => $user->id,
                'company' => $user->company_id ? [
                    'id' => $user->company_id,
                    'name' => $user->company?->name,
                    'slug' => $user->company?->slug,
                ] : null,
                'role' => $user->role_id ? [
                    'id' => $user->role_id,
                    'name' => null,
                ] : null,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'companies' => $companies,
            'memberships' => $memberships,
            'available_companies' => $availableCompanies,
            'spatie_roles' => $spatieRoles,
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        $validated = validator($data, [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where(fn ($q) => $q->where('company_id', $data['company_id'] ?? null)),
            ],
        ])->validate();

        $data['email'] = $validated['email'];
        $data['status'] = $data['status'] ?? 'active';
        $data['password'] = Hash::make((string) $data['password']);

        User::create($data);

        return redirect()->route('organization.users');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        $validated = validator($data, [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($user->id)
                    ->where(fn ($q) => $q->where('company_id', $data['company_id'] ?? null)),
            ],
        ])->validate();

        $data['email'] = $validated['email'];
        $data['status'] = $data['status'] ?? 'active';

        if (! empty($data['password'] ?? null)) {
            $data['password'] = Hash::make((string) $data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('organization.users');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->route('organization.users');
    }

    public function storeMembership(Request $request, User $user)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'in:active,inactive'],
            'role_id' => ['nullable', 'integer', 'exists:spatie_roles,id'],
        ]);

        $companyId = (int) $data['company_id'];

        $user->companies()->syncWithoutDetaching([
            $companyId => [
                'status' => (string) ($data['status'] ?? 'active'),
            ],
        ]);

        if (! empty($data['role_id'] ?? null)) {
            $role = SpatieRole::query()->whereKey((int) $data['role_id'])->firstOrFail();
            abort_unless((int) $role->company_id === $companyId, 422);

            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
            $user->syncRoles([$role->name]);
        }

        return redirect()->route('organization.users.show', $user);
    }

    public function updateMembership(Request $request, User $user, Company $company)
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,inactive'],
            'role_id' => ['nullable', 'integer', 'exists:spatie_roles,id'],
        ]);

        abort_unless($user->companies()->whereKey($company->id)->exists(), 404);

        $user->companies()->updateExistingPivot($company->id, [
            'status' => (string) $data['status'],
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        if (! empty($data['role_id'] ?? null)) {
            $role = SpatieRole::query()->whereKey((int) $data['role_id'])->firstOrFail();
            abort_unless((int) $role->company_id === (int) $company->id, 422);
            $user->syncRoles([$role->name]);
        } else {
            $user->syncRoles([]);
        }

        return redirect()->route('organization.users.show', $user);
    }

    public function destroyMembership(Request $request, User $user, Company $company)
    {
        $user->companies()->detach($company->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->syncRoles([]);

        return redirect()->route('organization.users.show', $user);
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = trim((string) $request->query('company_id', ''));
        $status = trim((string) $request->query('status', ''));

        $query = User::query()
            ->with(['company:id,name'])
            ->latest('id');

        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $export = new UsersExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "users_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $users = $query->get();
            $pdf = Pdf::loadView('exports.users', [
                'users' => $users,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
