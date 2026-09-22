<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Spatie\Permission\PermissionRegistrar;

test('all scope returns all company employees', function () {
    ['user' => $user, 'company' => $company, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $ids = EmployeeVisibilityScope::apply(Employee::query(), $user, $company->id)->pluck('id')->all();

    expect($ids)->toContain($marine->id, $office->id);
});

test('selected department scope returns only selected department employees', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $ids = EmployeeVisibilityScope::apply(Employee::query(), $user, $company->id)->pluck('id')->all();

    expect($ids)->toContain($marine->id)->not->toContain($office->id);
});

test('parent department scope includes active descendants', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $parent] = makeEmployeeVisibilityFixtures();

    $child = Department::query()->create([
        'company_id' => $company->id,
        'parent_id' => $parent->id,
        'name' => 'Offshore',
        'code' => 'OFFSH',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $childEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $child->id,
        'status' => 'active',
    ]);

    restrictUserToDepartments($user, $company, [$parent->id]);

    $ids = EmployeeVisibilityScope::apply(Employee::query(), $user, $company->id)->pluck('id')->all();

    expect($ids)->toContain($childEmployee->id);
});

test('null department employee is excluded from selected scope', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();

    $unassigned = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => null,
        'status' => 'active',
    ]);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    expect(EmployeeVisibilityScope::canAccess($user, $unassigned, $company->id))->toBeFalse();
});

test('empty selected scope fails closed', function () {
    ['user' => $user, 'company' => $company, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = $user->roles()->where('spatie_roles.company_id', $company->id)->first();
    $role->update(['employee_visibility_scope' => Role::SCOPE_SELECTED_DEPARTMENTS]);
    $role->employeeVisibilityDepartments()->detach();
    EmployeeVisibilityScope::clearCache();

    expect(EmployeeVisibilityScope::apply(Employee::query(), $user, $company->id)->count())->toBe(0);
    expect(EmployeeVisibilityScope::canAccess($user, $marine, $company->id))->toBeFalse();
});

test('user without role fails closed', function () {
    ['company' => $company, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();
    $user = User::factory()->create();

    expect(EmployeeVisibilityScope::canAccess($user, $marine, $company->id))->toBeFalse();
});

test('owner role remains unrestricted', function () {
    ['company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $ownerRole = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Owner',
        'guard_name' => 'web',
        'employee_visibility_scope' => Role::SCOPE_ALL,
    ]);
    $owner->syncRoles([$ownerRole]);
    EmployeeVisibilityScope::clearCache();

    expect(EmployeeVisibilityScope::canAccess($owner, $office, $company->id))->toBeTrue();
});

test('scope all role grants unrestricted access', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    expect(EmployeeVisibilityScope::canAccess($user, $office, $company->id))->toBeTrue();
});

test('unrestricted user cannot access employee from another company via canAccessId', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $otherCompany = Company::query()->create([
        'name' => 'Other Visibility Co',
        'slug' => 'other-visibility-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $foreignEmployee = Employee::factory()->create([
        'company_id' => $otherCompany->id,
        'status' => 'active',
    ]);

    expect(EmployeeVisibilityScope::canAccessId($user, (int) $foreignEmployee->id, (int) $company->id))->toBeFalse()
        ->and(EmployeeVisibilityScope::canAccessId($user, (int) $office->id, (int) $company->id))->toBeTrue();
});

test('cross-company departments cannot affect scope', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $otherCompany = Company::query()->create([
        'name' => 'Other Visibility Co',
        'slug' => 'other-visibility-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignDept = Department::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign',
        'code' => 'FOR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    restrictUserToDepartments($user, $company, [$marineDept->id, $foreignDept->id]);

    expect(EmployeeVisibilityScope::canAccess($user, $office, $company->id))->toBeFalse();
});

test('hidden employee direct profile access is denied', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $office))
        ->assertNotFound();
});

test('partial role update does not reset selected scope to all', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['roles.view', 'roles.update']);

    $role = restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.roles.update', $role), [
            'name' => 'Renamed Role Only',
            'permissions' => $role->permissions->pluck('name')->all(),
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect($role->fresh()->name)->toBe('Renamed Role Only')
        ->and($role->fresh()->employee_visibility_scope)->toBe(Role::SCOPE_SELECTED_DEPARTMENTS)
        ->and($role->fresh()->employeeVisibilityDepartments->pluck('id')->all())->toBe([$marineDept->id]);
});

test('partial role update preserves department selections', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['roles.view', 'roles.update']);

    $role = restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.roles.update', $role), [
            'name' => 'Updated Role Name',
            'permissions' => $role->permissions->pluck('name')->all(),
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect($role->fresh()->name)->toBe('Updated Role Name')
        ->and($role->fresh()->employee_visibility_scope)->toBe(Role::SCOPE_SELECTED_DEPARTMENTS)
        ->and($role->fresh()->employeeVisibilityDepartments->pluck('id')->all())->toBe([$marineDept->id]);
});

test('full visibility update persists selected departments', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['roles.view', 'roles.update']);

    $role = restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.roles.update', $role), [
            'name' => $role->name,
            'employee_visibility_scope' => Role::SCOPE_SELECTED_DEPARTMENTS,
            'department_ids' => [$marineDept->id, $officeDept->id],
            'permissions' => $role->permissions->pluck('name')->all(),
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect($role->fresh()->employee_visibility_scope)->toBe(Role::SCOPE_SELECTED_DEPARTMENTS)
        ->and($role->fresh()->employeeVisibilityDepartments->pluck('id')->sort()->values()->all())
        ->toBe(collect([$marineDept->id, $officeDept->id])->sort()->values()->all());
});

test('explicit change to all detaches selected departments', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['roles.view', 'roles.update']);

    $role = restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.roles.update', $role), [
            'name' => $role->name,
            'employee_visibility_scope' => Role::SCOPE_ALL,
            'department_ids' => [],
            'permissions' => $role->permissions->pluck('name')->all(),
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect($role->fresh()->employee_visibility_scope)->toBe(Role::SCOPE_ALL)
        ->and($role->fresh()->employeeVisibilityDepartments)->toHaveCount(0);
});

test('owner role update rejects selected department visibility', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['roles.view', 'roles.update']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $ownerRole = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Owner',
        'guard_name' => 'web',
        'employee_visibility_scope' => Role::SCOPE_ALL,
    ]);
    $ownerRole->syncPermissions(['roles.view', 'roles.update']);

    $this->actingAs($user)
        ->put(route('organization.roles.update', $ownerRole), [
            'name' => 'Owner',
            'employee_visibility_scope' => Role::SCOPE_SELECTED_DEPARTMENTS,
            'department_ids' => [$marineDept->id],
        ])
        ->assertSessionHasErrors('employee_visibility_scope');

    expect($ownerRole->fresh()->employee_visibility_scope)->toBe(Role::SCOPE_ALL)
        ->and($ownerRole->fresh()->employeeVisibilityDepartments)->toHaveCount(0);
});

test('restricted actor cannot create employee in hidden department', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['employees.create']);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'name' => 'New Office Hire',
            'employee_no' => 'OFF-001',
            'department_id' => $officeDept->id,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ])
        ->assertSessionHasErrors('department_id');
});

test('role scope changes take effect without clearCache', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    restrictUserToDepartments($user, $company, [$marineDept->id]);
    expect(EmployeeVisibilityScope::canAccess($user, $marine, $company->id))->toBeTrue()
        ->and(EmployeeVisibilityScope::canAccess($user, $office, $company->id))->toBeFalse();

    restrictUserToDepartments($user, $company, [$officeDept->id]);

    expect(EmployeeVisibilityScope::canAccess($user, $office, $company->id))->toBeTrue()
        ->and(EmployeeVisibilityScope::canAccess($user, $marine, $company->id))->toBeFalse();
});

test('restricted actor cannot create employee with null department', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['employees.create']);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'name' => 'Unassigned Hire',
            'employee_no' => 'UNA-001',
            'department_id' => null,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ])
        ->assertSessionHasErrors('department_id');
});

test('restricted actor cannot move employee to null department', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['employees.update']);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.employees.update', $marine), [
            'name' => $marine->name,
            'employee_no' => $marine->employee_no,
            'department_id' => null,
            'start_date' => $marine->start_date?->toDateString() ?? now()->toDateString(),
            'status' => 'active',
        ])
        ->assertSessionHasErrors('department_id');
});

test('restricted actor cannot move employee to hidden department', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();
    grantCompanyPermissions($user, $company, ['employees.update']);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.employees.update', $marine), [
            'name' => $marine->name,
            'employee_no' => $marine->employee_no,
            'department_id' => $officeDept->id,
            'start_date' => $marine->start_date?->toDateString() ?? now()->toDateString(),
            'status' => 'active',
        ])
        ->assertSessionHasErrors('department_id');
});
