<?php

namespace App\Http\Controllers\Organization;

use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\User\DestroyUserMembershipRequest;
use App\Http\Requests\Organization\User\StoreUserMembershipRequest;
use App\Http\Requests\Organization\User\StoreUserRequest;
use App\Http\Requests\Organization\User\UpdateUserMembershipRequest;
use App\Http\Requests\Organization\User\UpdateUserRequest;
use App\Http\Requests\Organization\User\UpdateUserStatusRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Users\Actions\CopyEmployeeAvatarToUser;
use App\Support\Users\Actions\CreateOrganizationUser;
use App\Support\Users\Actions\SyncUserEmployeeLink;
use App\Support\Users\Actions\UpdateOrganizationUser;
use App\Support\Users\GlobalIdentityAccessGuard;
use App\Support\Users\LastCompanyOwnerGuard;
use App\Support\Users\UserAvatar;
use App\Support\Users\UserDirectoryQuery;
use App\Support\Users\UserMembershipAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserController extends Controller
{
    use ResolvesPerPage;

    private function avatarUrl(?string $value): ?string
    {
        return UserAvatar::url($value);
    }

    /**
     * @return array{id: int, name: string, slug: string|null}|null
     */
    private function companyPayload(?Company $company): ?array
    {
        if ($company === null) {
            return null;
        }

        return [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
        ];
    }

    /**
     * @return array{id: int, name: string, employee_no: string, image_url: string|null}|null
     */
    private function linkedEmployeePayload(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'employee_no' => $employee->employee_no,
            'image_url' => $employee->image ? $this->avatarUrl($employee->image) : null,
        ];
    }

    /**
     * @return list<array{id: int, name: string, employee_no: string, user_id: int|null, image_url: string|null}>
     */
    private function employeesForLinking(int $companyId): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no', 'user_id', 'image'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'user_id' => $employee->user_id,
                'image_url' => $employee->image ? $this->avatarUrl($employee->image) : null,
            ])
            ->all();
    }

    public function index(UserDirectoryQuery $query)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $search = trim((string) request()->query('search', ''));
        $status = trim((string) request()->query('status', ''));
        $roleId = trim((string) request()->query('role_id', ''));
        $presence = trim((string) request()->query('presence', ''));
        $view = trim((string) request()->query('view', '')) === 'invitations'
            ? 'invitations'
            : '';

        $paginator = $query->paginateForCompany(
            $companyId,
            $perPage,
            $search,
            $status,
            $roleId,
            $presence
        );

        $summary = $query->summaryForCompany($companyId);

        $users = $paginator;

        $roleRows = DB::table('spatie_model_has_roles')
            ->join('spatie_roles', 'spatie_roles.id', '=', 'spatie_model_has_roles.role_id')
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->whereIn('spatie_model_has_roles.model_id', $users->getCollection()->pluck('id')->all())
            ->orderBy('spatie_roles.name')
            ->get([
                'spatie_model_has_roles.model_id as user_id',
                'spatie_roles.id as role_id',
                'spatie_roles.name as role_name',
            ]);

        $roleByUserId = $roleRows
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());

        $employeeByUserId = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->get(['id', 'user_id', 'name', 'employee_no', 'image'])
            ->keyBy('user_id');

        $company = Company::query()->whereKey($companyId)->first(['id', 'name', 'slug']);
        $companyPayload = $this->companyPayload($company);

        $now = time();
        $onlineThreshold = $now - (5 * 60);
        $recentThreshold = $now - (30 * 60);

        $requestUser = request()->user();
        $users->setCollection(
            $users->getCollection()->map(function (User $user) use ($roleByUserId, $employeeByUserId, $companyPayload, $onlineThreshold, $recentThreshold, $companyId, $requestUser) {
                $role = $roleByUserId->get($user->id);
                $latestActivity = $user->latest_activity ? (int) $user->latest_activity : null;
                $isHomeCompanyUser = (int) $user->company_id === $companyId;

                $presenceState = 'never';
                if ($latestActivity) {
                    if ($latestActivity >= $onlineThreshold) {
                        $presenceState = 'online';
                    } elseif ($latestActivity >= $recentThreshold) {
                        $presenceState = 'recent';
                    } else {
                        $presenceState = 'offline';
                    }
                } elseif ($user->last_login_at) {
                    $presenceState = 'offline';
                }

                return [
                    'id' => $user->id,
                    'company' => $user->company_id ? $companyPayload : null,
                    'role' => $role ? [
                        'id' => (int) $role->role_id,
                        'name' => (string) $role->role_name,
                    ] : null,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $this->avatarUrl($user->avatar),
                    'status' => $user->status,
                    'last_login_at' => $user->last_login_at,
                    'last_active_at' => $latestActivity ? Carbon::createFromTimestamp($latestActivity)->toIso8601String() : null,
                    'presence' => $presenceState,
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                    'created_at' => $user->created_at,
                    'linked_employee' => $this->linkedEmployeePayload($employeeByUserId->get($user->id)),
                    'capabilities' => [
                        'can_edit_global_identity' => $isHomeCompanyUser,
                        'can_delete_global_identity' => $isHomeCompanyUser,
                        'can_password_reset' => $isHomeCompanyUser && ($requestUser?->can('users.password_reset') ?? false),
                        'can_revoke_sessions' => $isHomeCompanyUser && ($requestUser?->can('users.sessions.revoke') ?? false),
                        'can_manage_membership' => true,
                    ],
                ];
            })
        );

        $roles = SpatieRole::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $invitations = UserInvitation::with('role')
            ->where('company_id', $companyId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role ? [
                    'id' => $invitation->role->id,
                    'name' => $invitation->role->name,
                ] : null,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'last_sent_at' => $invitation->last_sent_at?->toIso8601String(),
                'created_at' => $invitation->created_at->toIso8601String(),
            ]);

        $summary['pending_invites'] = $invitations->count();

        return Inertia::render('organization/users', [
            'users' => $users->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'status' => $status,
                'role_id' => $roleId,
                'presence' => $presence,
                'view' => $view,
            ],
            'summary' => $summary,
            'roles' => $roles,
            'invitations' => $invitations,
            'employees_for_linking' => $this->employeesForLinking($companyId),
        ]);
    }

    public function show(User $user)
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        // Allow if home company OR active company_user membership
        $isHomeCompanyUser = (int) $user->company_id === $companyId;
        $isMember = $isHomeCompanyUser
            || $user->companies()
                ->wherePivot('company_id', $companyId)
                ->wherePivot('status', 'active')
                ->exists();
        abort_unless($isMember, 404);

        $role = DB::table('spatie_model_has_roles')
            ->join('spatie_roles', 'spatie_roles.id', '=', 'spatie_model_has_roles.role_id')
            ->where('spatie_model_has_roles.model_type', User::class)
            ->where('spatie_model_has_roles.model_id', $user->id)
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->orderBy('spatie_roles.name')
            ->first([
                'spatie_roles.id as role_id',
                'spatie_roles.name as role_name',
            ]);

        $roles = SpatieRole::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $request = request();

        $linkedEmployee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first(['id', 'name', 'employee_no', 'image']);

        $company = Company::query()->whereKey($companyId)->first(['id', 'name', 'slug']);

        return Inertia::render('organization/user', [
            'user' => [
                'id' => $user->id,
                'company' => $user->company_id ? $this->companyPayload($company) : null,
                'role' => $role ? [
                    'id' => (int) $role->role_id,
                    'name' => (string) $role->role_name,
                ] : null,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $this->avatarUrl($user->avatar),
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'linked_employee' => $this->linkedEmployeePayload($linkedEmployee),
                'capabilities' => [
                    'can_edit_global_identity' => $isHomeCompanyUser,
                    'can_delete_global_identity' => $isHomeCompanyUser,
                    'can_password_reset' => $isHomeCompanyUser && ($request->user()?->can('users.password_reset') ?? false),
                    'can_revoke_sessions' => $isHomeCompanyUser && ($request->user()?->can('users.sessions.revoke') ?? false),
                    'can_manage_membership' => true,
                ],
            ],
            'roles' => $roles,
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                User::class,
                $user->id,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
            'employees_for_linking' => $this->employeesForLinking($companyId),
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $data['company_id'] = $companyId;
        $roleId = $data['role_id'] ?? null;
        $employeeId = isset($data['employee_id']) && $data['employee_id'] !== '' ? (int) $data['employee_id'] : null;
        unset($data['role_id'], $data['employee_id']);

        $createdUser = app(CreateOrganizationUser::class)->handle(
            $companyId,
            (string) $data['name'],
            (string) $data['email'],
            (string) $data['password'],
            $roleId ? (int) $roleId : null,
            ['status' => $data['status'] ?? 'active'],
            $request->file('avatar'),
        );

        if ($employeeId !== null) {
            app(SyncUserEmployeeLink::class)->handle($createdUser, $companyId, $employeeId);

            if ($request->boolean('use_employee_avatar')) {
                app(CopyEmployeeAvatarToUser::class)->handle($createdUser->fresh(), $companyId);
            }
        }

        return redirect()
            ->route('organization.users')
            ->with('success', 'User created successfully.');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        $data = $request->validated();
        $roleId = isset($data['role_id']) && $data['role_id'] !== '' && $data['role_id'] !== null
            ? (int) $data['role_id']
            : null;
        $employeeId = isset($data['employee_id']) && $data['employee_id'] !== '' && $data['employee_id'] !== null
            ? (int) $data['employee_id']
            : null;

        try {
            app(UpdateOrganizationUser::class)->handle(
                $user,
                $companyId,
                [
                    'name' => (string) $data['name'],
                    'email' => (string) $data['email'],
                    'status' => (string) ($data['status'] ?? 'active'),
                ],
                $roleId,
                $employeeId,
                $request->file('avatar'),
                $request->boolean('use_employee_avatar'),
            );
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 400) {
                return back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return redirect()
            ->route('organization.users')
            ->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        try {
            DB::transaction(function () use ($user, $companyId) {
                if (! LastCompanyOwnerGuard::check($user, $companyId)) {
                    abort(400, 'Cannot delete user: the company must have at least one active Owner.');
                }
                $user->delete();
            });
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 400) {
                return back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return redirect()
            ->route('organization.users')
            ->with('success', 'User deleted successfully.');
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        GlobalIdentityAccessGuard::check($user, $companyId);

        try {
            DB::transaction(function () use ($user, $request, $companyId) {
                if ($request->validated('status') !== 'active' && ! LastCompanyOwnerGuard::check($user, $companyId)) {
                    abort(400, 'Cannot deactivate user: the company must have at least one active Owner.');
                }

                $user->update([
                    'status' => $request->validated('status'),
                ]);
            });
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 400) {
                return back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return redirect()
            ->route('organization.users')
            ->with('success', 'User status updated successfully.');
    }

    public function storeMembership(StoreUserMembershipRequest $request, User $user)
    {
        $companyId = UserMembershipAccess::assertActiveCompany($request);
        $data = $request->validated();
        $status = (string) ($data['status'] ?? 'active');
        $roleId = isset($data['role_id']) && $data['role_id'] !== null && $data['role_id'] !== ''
            ? (int) $data['role_id']
            : null;

        $user->companies()->syncWithoutDetaching([
            $companyId => [
                'status' => $status,
            ],
        ]);

        if ($roleId !== null) {
            UserMembershipAccess::syncRole($user, $companyId, $roleId);
        }

        UserMembershipAccess::log($request, $user, $companyId, 'added company membership', [
            'status' => $status,
            'role_id' => $roleId,
        ]);

        return redirect()
            ->route('organization.users.show', $user)
            ->with('success', 'Membership added successfully.');
    }

    public function updateMembership(UpdateUserMembershipRequest $request, User $user, Company $company)
    {
        $companyId = (int) $company->id;
        $data = $request->validated();
        $status = (string) $data['status'];
        $roleId = isset($data['role_id']) && $data['role_id'] !== null && $data['role_id'] !== ''
            ? (int) $data['role_id']
            : null;

        try {
            DB::transaction(function () use ($user, $companyId, $status, $roleId, $request) {
                if ($status !== 'active' || $roleId !== null) {
                    $isRoleChangeToNonOwner = false;
                    if ($roleId !== null) {
                        $newRole = SpatieRole::find($roleId);
                        if (! $newRole || $newRole->name !== 'Owner') {
                            $isRoleChangeToNonOwner = true;
                        }
                    }
                    if ($status !== 'active' || $isRoleChangeToNonOwner) {
                        if (! LastCompanyOwnerGuard::check($user, $companyId)) {
                            abort(400, 'Cannot perform this action: the company must have at least one active Owner.');
                        }
                    }
                }

                $user->companies()->updateExistingPivot($companyId, [
                    'status' => $status,
                ]);

                UserMembershipAccess::syncRole($user, $companyId, $roleId);

                UserMembershipAccess::log($request, $user, $companyId, 'updated company membership', [
                    'status' => $status,
                    'role_id' => $roleId,
                ]);
            });
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 400) {
                return back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return redirect()
            ->route('organization.users.show', $user)
            ->with('success', 'Membership updated successfully.');
    }

    public function destroyMembership(DestroyUserMembershipRequest $request, User $user, Company $company)
    {
        $companyId = (int) $company->id;

        try {
            DB::transaction(function () use ($user, $companyId, $request) {
                if (! LastCompanyOwnerGuard::check($user, $companyId)) {
                    abort(400, 'Cannot remove membership: the company must have at least one active Owner.');
                }

                $user->companies()->detach($companyId);

                UserMembershipAccess::syncRole($user, $companyId, null);

                UserMembershipAccess::log($request, $user, $companyId, 'removed company membership');
            });
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 400) {
                return back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return redirect()
            ->route('organization.users.show', $user)
            ->with('success', 'Membership removed successfully.');
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
