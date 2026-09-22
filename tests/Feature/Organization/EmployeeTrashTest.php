<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company, branch: Branch, department: Department, position: Position}
 */
function makeEmployeeTrashFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'ETR',
        'name' => 'Employee Trash Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ETR',
        'name' => 'Employee Trash Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Employee Trash Co',
        'slug' => 'employee-trash-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Office',
        'code' => 'MO',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Technician',
        'grade' => 'G3',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'branch', 'department', 'position');
}

test('creating an employee rejects a soft deleted employee number with a restore message', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $deleted = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '3119',
            'name' => 'Deleted Employee',
        ]);
    $deleted->delete();

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $this->from('/organization/employees/create')
        ->post('/organization/employees', [
            'employee_no' => '3119',
            'name' => 'Replacement Employee',
            'start_date' => '2026-02-01',
        ])
        ->assertSessionHasErrors([
            'employee_no' => 'Employee No. 3119 belongs to a deleted employee. Restore the existing employee from Employees > Deleted instead of creating a duplicate.',
        ]);

    expect(Employee::query()->where('employee_no', '3119')->count())->toBe(0);
});

test('creating an employee rejects a duplicate active employee number with the standard message', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '1001',
            'name' => 'Active Employee',
        ]);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $this->from('/organization/employees/create')
        ->post('/organization/employees', [
            'employee_no' => '1001',
            'name' => 'Duplicate Employee',
            'start_date' => '2026-02-01',
        ])
        ->assertSessionHasErrors([
            'employee_no' => 'This employee number is already used in your company. Choose a different number.',
        ]);
});

test('updating another employee rejects a soft deleted employee number', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $deleted = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '1',
            'name' => 'Deleted Employee',
        ]);
    $deleted->delete();

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'DRAFT-NEW',
            'name' => 'Draft Employee',
        ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => '1',
            'name' => $employee->name,
        ])
        ->assertSessionHasErrors([
            'employee_no' => 'Employee No. 1 belongs to a deleted employee. Restore the existing employee from Employees > Deleted instead of creating a duplicate.',
        ]);

    expect($employee->fresh()->employee_no)->toBe('DRAFT-NEW');
});

test('updating an employee without changing their own employee number still works', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'KEEP-001',
            'name' => 'Keep Number Employee',
        ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'KEEP-001',
            'name' => 'Updated Name',
        ])
        ->assertRedirect("/organization/employees/{$employee->id}");

    expect($employee->fresh()->name)->toBe('Updated Name')
        ->and($employee->fresh()->employee_no)->toBe('KEEP-001');
});

test('authorized users can view deleted employees for their company only', function () {
    ['user' => $user, 'company' => $company, 'branch' => $branch, 'department' => $department, 'position' => $position] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $deleted = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'DEL-001',
            'name' => 'Deleted Worker',
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'work_email' => 'deleted@example.com',
            'phone' => '+971500000001',
            'status' => 'inactive',
        ]);
    $deleted->delete();

    $active = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'ACT-001',
            'name' => 'Active Worker',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.delete']);

    $this->get('/organization/employees/deleted')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees-deleted')
            ->has('employees', 1)
            ->where('employees.0.id', $deleted->id)
            ->where('employees.0.employee_no', 'DEL-001')
            ->where('employees.0.name', 'Deleted Worker')
            ->where('employees.0.branch.name', 'Main Office')
            ->where('employees.0.department.name', 'Operations')
            ->where('employees.0.position.title', 'Technician')
            ->where('employees.0.work_email', 'deleted@example.com')
            ->where('employees.0.phone', '+971500000001')
            ->where('employees.0.status', 'inactive')
            ->where('can.manage_deleted', true));

    expect($active->fresh()->trashed())->toBeFalse();
});

test('deleted employees directory can be searched by employee number', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $target = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'SEARCH-42',
            'name' => 'Search Target',
        ]);
    $target->delete();

    $other = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'OTHER-99',
            'name' => 'Other Deleted',
        ]);
    $other->delete();

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.delete']);

    $this->get('/organization/employees/deleted?search=SEARCH-42')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees-deleted')
            ->has('employees', 1)
            ->where('employees.0.id', $target->id));
});

test('authorized users can restore a soft deleted employee', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $deleted = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'REST-001',
            'name' => 'Restore Me',
        ]);
    $deleted->delete();

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.delete']);

    $this->from('/organization/employees/deleted?search=REST&per_page=10')
        ->post("/organization/employees/deleted/{$deleted->id}/restore?search=REST&per_page=10")
        ->assertRedirect('/organization/employees/deleted?search=REST&per_page=10')
        ->assertSessionHas(
            'success',
            'Employee No. REST-001 restored successfully. The employee retains their previous status.',
        );

    expect($deleted->fresh()->trashed())->toBeFalse()
        ->and($deleted->fresh()->status)->toBe('active');

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'restored',
        'subject_type' => Employee::class,
        'subject_id' => $deleted->id,
    ]);
});

test('restore redirects to the previous page when the current page becomes empty', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    foreach (range(1, 10) as $index) {
        $employee = Employee::factory()
            ->forCompany($company)
            ->create([
                'employee_no' => sprintf('KEEP-%03d', $index),
                'name' => "Keep Deleted {$index}",
            ]);
        $employee->delete();
    }

    $lastOnPage = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'LAST-001',
            'name' => 'Last On Page',
        ]);
    $lastOnPage->delete();

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.delete']);

    $this->from('/organization/employees/deleted?page=2&per_page=10')
        ->post("/organization/employees/deleted/{$lastOnPage->id}/restore?page=2&per_page=10")
        ->assertRedirect('/organization/employees/deleted?per_page=10')
        ->assertSessionHas('success');

    expect($lastOnPage->fresh()->trashed())->toBeFalse();
});

test('company a cannot view or restore company b deleted employees', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();

    $deleted = Employee::factory()
        ->forCompany($companyB)
        ->create([
            'employee_no' => 'ISO-001',
            'name' => 'Company B Deleted',
        ]);
    $deleted->delete();

    grantCompanyPermissions($user, $companyA, ['employees.view', 'employees.delete']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/employees/deleted')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees-deleted')
            ->has('employees', 0));

    $this->withSession(['current_company_id' => $companyA->id])
        ->from('/organization/employees/deleted')
        ->post("/organization/employees/deleted/{$deleted->id}/restore")
        ->assertNotFound();

    expect($deleted->fresh()->trashed())->toBeTrue();
});

test('users without employee delete permission cannot access deleted employees or restore', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $deleted = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'PERM-001',
            'name' => 'Permission Deleted',
        ]);
    $deleted->delete();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/employees/deleted')->assertForbidden();

    $this->from('/organization/employees/deleted')
        ->post("/organization/employees/deleted/{$deleted->id}/restore")
        ->assertForbidden();

    expect($deleted->fresh()->trashed())->toBeTrue();
});

test('deleted employees directory respects role employee visibility scope', function () {
    ['user' => $user, 'company' => $company, 'branch' => $branch] = makeEmployeeTrashFixtures();
    $this->actingAs($user);

    $crewDepartment = Department::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Crew Department',
        'code' => 'CREW',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Office Department',
        'code' => 'OFFICE',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $deletedCrewEmployee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'CREW-DEL-001',
            'name' => 'Deleted Crew Employee',
            'department_id' => $crewDepartment->id,
        ]);
    $deletedCrewEmployee->delete();

    $deletedOfficeEmployee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'OFFICE-DEL-001',
            'name' => 'Deleted Office Employee',
            'department_id' => $officeDepartment->id,
        ]);
    $deletedOfficeEmployee->delete();

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.delete']);
    restrictTestRoleEmployeeVisibility($user, $company, [$crewDepartment->id]);

    $this->get('/organization/employees/deleted')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees-deleted')
            ->has('employees', 1)
            ->where('employees.0.id', $deletedCrewEmployee->id)
            ->where('employees.0.employee_no', 'CREW-DEL-001'));

    $this->from('/organization/employees/deleted')
        ->post("/organization/employees/deleted/{$deletedOfficeEmployee->id}/restore")
        ->assertNotFound();

    expect($deletedOfficeEmployee->fresh()->trashed())->toBeTrue();

    $this->from('/organization/employees/deleted')
        ->post("/organization/employees/deleted/{$deletedCrewEmployee->id}/restore")
        ->assertRedirect('/organization/employees/deleted')
        ->assertSessionHas('success');

    expect($deletedCrewEmployee->fresh()->trashed())->toBeFalse();
});
