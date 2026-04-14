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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    private function avatarUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return Storage::url($value);
    }

    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $users = User::query()
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'company' => $user->company_id ? [
                    'id' => $user->company_id,
                    'name' => null,
                ] : null,
                'role' => null,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $this->avatarUrl($user->avatar),
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('organization/users', [
            'users' => $users,
        ]);
    }

    public function show(User $user)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $user->company_id === $companyId, 404);

        return Inertia::render('organization/user', [
            'user' => [
                'id' => $user->id,
                'company' => $user->company_id ? [
                    'id' => $user->company_id,
                    'name' => null,
                    'slug' => null,
                ] : null,
                'role' => $user->role_id ? [
                    'id' => $user->role_id,
                    'name' => null,
                ] : null,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $this->avatarUrl($user->avatar),
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $data['company_id'] = $companyId;

        $validated = validator($data, [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ])->validate();

        $data['email'] = $validated['email'];
        $data['status'] = $data['status'] ?? 'active';
        $data['password'] = Hash::make((string) $data['password']);

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $request->file('avatar')->store('user-avatars', 'public');
        }

        $user = User::create($data);

        $user->companies()->syncWithoutDetaching([
            $companyId => ['status' => 'active'],
        ]);

        return redirect()->route('organization.users');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $user->company_id === $companyId, 404);

        $data = $request->validated();
        $data['company_id'] = $companyId;

        $validated = validator($data, [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($user->id)
                    ->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ])->validate();

        $data['email'] = $validated['email'];
        $data['status'] = $data['status'] ?? 'active';

        if (! empty($data['password'] ?? null)) {
            $data['password'] = Hash::make((string) $data['password']);
        } else {
            unset($data['password']);
        }

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $request->file('avatar')->store('user-avatars', 'public');
        } else {
            unset($data['avatar']);
        }

        $user->update($data);

        return redirect()->route('organization.users');
    }

    public function destroy(User $user)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $user->company_id === $companyId, 404);

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
        $companyId = (int) $request->attributes->get('current_company_id');
        $status = trim((string) $request->query('status', ''));

        $query = User::query()
            ->where('company_id', $companyId)
            ->latest('id');

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
