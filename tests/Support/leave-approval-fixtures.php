<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensure the company has an active default leave approval policy.
 *
 * @param  list<array{type: LeaveApprovalApproverType|string, employee_id?: int|null, required?: bool}>|null  $steps
 */
function ensureDefaultLeaveApprovalPolicy(Company $company, ?array $steps = null): LeaveApprovalPolicy
{
    $steps ??= [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ];

    $policy = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->default()
        ->withSteps($steps)
        ->create();

    return $policy;
}

/**
 * Create an actionable approver (active employee + linked active user + approve permission).
 *
 * @return array{employee: Employee, user: User}
 */
function makeActionableApprover(Company $company, array $employeeAttributes = []): array
{
    $user = User::factory()->create(['status' => 'active']);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Use a unique role per approver so later grantCompanyPermissions() calls for
    // other users in the same company do not wipe this role's approve permission.
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $permissions = [];
    foreach ([
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ] as $name) {
        $permissions[] = Permission::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $role = Role::query()->firstOrCreate([
        'company_id' => $company->id,
        'name' => 'leave-approver-'.$user->id,
        'guard_name' => 'web',
    ]);
    $role->syncPermissions($permissions);
    $user->syncRoles([$role]);

    $employee = Employee::factory()->forCompany($company)->create(array_merge([
        'status' => 'active',
        'user_id' => $user->id,
    ], $employeeAttributes));

    return ['employee' => $employee, 'user' => $user];
}

/**
 * Configure department manager + optional parent for leave approval workflow tests.
 *
 * @return array{department: Department, manager: Employee, managerUser: User, parent?: Department, parentManager?: Employee, parentManagerUser?: User}
 */
function makeManagedDepartment(Company $company, bool $withParent = false): array
{
    ['employee' => $manager, 'user' => $managerUser] = makeActionableApprover($company, [
        'name' => 'Dept Manager',
        'work_email' => 'dept-manager@example.com',
    ]);

    $parent = null;
    $parentManager = null;
    $parentManagerUser = null;

    if ($withParent) {
        ['employee' => $parentManager, 'user' => $parentManagerUser] = makeActionableApprover($company, [
            'name' => 'Parent Manager',
            'work_email' => 'parent-manager@example.com',
        ]);

        $parent = Department::query()->create([
            'company_id' => $company->id,
            'name' => 'Parent Dept',
            'code' => 'PAR',
            'manager_id' => $parentManager->id,
            'status' => 'active',
        ]);
    }

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Child Dept',
        'code' => 'CHD',
        'parent_id' => $parent?->id,
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    return array_filter([
        'department' => $department,
        'manager' => $manager,
        'managerUser' => $managerUser,
        'parent' => $parent,
        'parentManager' => $parentManager,
        'parentManagerUser' => $parentManagerUser,
    ], fn ($value) => $value !== null);
}

function configureCompanyLeaveApprovalSettings(
    Company $company,
    ?Employee $hrApprover = null,
    ?Employee $fallbackApprover = null,
): CompanyLeaveApprovalSetting {
    $settings = CompanyLeaveApprovalSetting::forCompany($company->id);
    $settings->update([
        'default_hr_approver_employee_id' => $hrApprover?->id,
        'fallback_approver_employee_id' => $fallbackApprover?->id,
    ]);

    return $settings->fresh();
}

/**
 * Prepare a default manager-only policy and attach the employee to a managed department.
 *
 * @return array{manager: Employee, managerUser: User, department: Department}
 */
function prepareLeaveRequestApprovalContext(Company $company, Employee $employee): array
{
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);
    $employee->update(['department_id' => $managed['department']->id]);

    return $managed;
}
