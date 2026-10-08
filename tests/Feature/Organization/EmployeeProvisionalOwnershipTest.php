<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Employees\DraftEmployeeNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function makeProvisionalOwnershipFixtures(string $slugSuffix = 'own'): array
{
    $user = User::factory()->create();
    $code = strtoupper(substr(md5($slugSuffix), 0, 3));

    $country = Country::query()->create([
        'code' => $code,
        'name' => "Ownership Land {$slugSuffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => "Ownership Currency {$slugSuffix}",
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => "Ownership Co {$slugSuffix}",
        'slug' => "ownership-{$slugSuffix}",
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company');
}

test('creator can finalize their own provisional employee', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('own-ok');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Owned Draft',
        'idempotency_key' => 'owner-key-aaaaaaaa',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');
    $employee = Employee::query()->findOrFail($employeeId);

    expect($employee->provisional_created_by)->toBe($user->id)
        ->and(DraftEmployeeNumber::isDraft($employee->employee_no))->toBeTrue();

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'OWN-001',
            'name' => 'Owned Draft',
        ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success');

    expect($employee->fresh()->employee_no)->toBe('OWN-001')
        ->and($employee->fresh()->provisional_ensure_key)->toBeNull();
});

test('creator cannot modify another users provisional employee', function () {
    ['user' => $creator, 'company' => $company] = makeProvisionalOwnershipFixtures('own-a');
    $intruder = User::factory()->create();
    $this->actingAs($creator);
    grantCompanyPermissions($creator, $company, ['employees.create']);
    grantCompanyPermissions($intruder, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Creator Draft',
        'idempotency_key' => 'owner-key-bbbbbbbb',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');

    $this->actingAs($intruder)
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'HACK-001',
            'name' => 'Creator Draft',
        ])
        ->assertForbidden();

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});

test('create-only user cannot modify finalized employees', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('final');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'FINAL-1',
        'name' => 'Final Employee',
        'provisional_created_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'FINAL-1',
        'name' => 'Changed',
    ])->assertForbidden();

    expect($employee->fresh()->name)->toBe('Final Employee');
});

test('create-only user cannot open another employee via create employee_id query', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-other');
    $other = User::factory()->create();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);
    grantCompanyPermissions($other, $company, ['employees.create']);

    $this->actingAs($other);
    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Other Draft',
        'idempotency_key' => 'owner-key-cccccccc',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->actingAs($user)
        ->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertForbidden();
});

test('create-only user cannot use create url to read a finalized employee', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-final');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SECRET-1',
        'name' => 'Sensitive Employee',
        'emirates_id' => '784-1234-1234567-1',
        'passport_number' => 'P1234567',
        'provisional_created_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->get("/organization/employees/create?employee_id={$employee->id}")
        ->assertForbidden();
});

test('users cannot access provisional employees belonging to another company', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('xcomp-a');
    ['company' => $otherCompany] = makeProvisionalOwnershipFixtures('xcomp-b');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update']);

    $foreign = Employee::factory()->forCompany($otherCompany)->create([
        'employee_no' => 'DRAFT-FOREIGN1',
        'name' => 'Foreign Draft',
        'provisional_created_by' => $user->id,
    ]);

    $this->put("/organization/employees/{$foreign->id}", [
        'employee_no' => 'XCOMP-1',
        'name' => 'Foreign Draft',
    ])->assertNotFound();

    $this->get("/organization/employees/create?employee_id={$foreign->id}")
        ->assertNotFound();

    expect($foreign->fresh()->employee_no)->toBe('DRAFT-FOREIGN1');
});

test('department-restricted creator can complete their own null-department draft', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('dept-ok');
    $this->actingAs($user);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$department->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Dept Draft',
        'idempotency_key' => 'owner-key-dddddddd',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');
    expect(Employee::query()->findOrFail($employeeId)->department_id)->toBeNull();

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'DEPT-001',
            'name' => 'Dept Draft',
            'department_id' => $department->id,
        ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success');

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toBe('DEPT-001')
        ->and(Employee::query()->findOrFail($employeeId)->department_id)->toBe($department->id);
});

test('department-restricted creator cannot assign unauthorized departments', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('dept-deny');
    $this->actingAs($user);

    $allowed = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR2',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $denied = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF2',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$allowed->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Dept Denied',
        'idempotency_key' => 'owner-key-eeeeeeee',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'DEPT-BAD',
            'name' => 'Dept Denied',
            'department_id' => $denied->id,
        ])
        ->assertSessionHasErrors('department_id');

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});

test('employees.update still allows authorized profile edits', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('updater');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'UPD-001',
        'name' => 'Updatable',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'UPD-001',
            'name' => 'Updated Name',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($employee->fresh()->name)->toBe('Updated Name');
});

test('create-only completion redirects to create not the view-only profile', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('redir');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Redirect Draft',
        'idempotency_key' => 'owner-key-ffffffff',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'REDIR-1',
        'name' => 'Redirect Draft',
    ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success', 'Employee created successfully.');

    $this->get("/organization/employees/{$employeeId}")->assertForbidden();
});

test('users with view permission still redirect to the employee profile after save', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('view-redir');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'View Redirect',
        'idempotency_key' => 'owner-key-gggggggg',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'VIEW-1',
        'name' => 'View Redirect',
    ])
        ->assertRedirect(route('organization.employees.show', ['employee' => $employeeId]))
        ->assertSessionHas('success');
});

test('unowned legacy drafts cannot be claimed through a guessed employee id', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('legacy');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $legacy = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-LEGACY01',
        'name' => 'Legacy Draft',
        'provisional_created_by' => null,
        'emirates_id' => '784-9999-9999999-9',
    ]);

    $this->get("/organization/employees/create?employee_id={$legacy->id}")
        ->assertForbidden();

    $this->put("/organization/employees/{$legacy->id}", [
        'employee_no' => 'CLAIM-1',
        'name' => 'Legacy Draft',
    ])->assertForbidden();

    expect($legacy->fresh()->employee_no)->toBe('DRAFT-LEGACY01');
});

test('repeated ensure requests with the same idempotency key reuse one provisional record', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('idem');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $first = $this->postJson('/organization/employees/ensure', [
        'name' => 'Idempotent',
        'idempotency_key' => 'owner-key-hhhhhhhh',
    ])->assertOk();

    $second = $this->postJson('/organization/employees/ensure', [
        'name' => 'Idempotent Retry',
        'idempotency_key' => 'owner-key-hhhhhhhh',
    ])->assertOk();

    expect((int) $first->json('employee.id'))->toBe((int) $second->json('employee.id'))
        ->and(Employee::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('idempotency keys cannot reuse another users provisional employee', function () {
    ['user' => $creator, 'company' => $company] = makeProvisionalOwnershipFixtures('idem-iso');
    $other = User::factory()->create();
    grantCompanyPermissions($creator, $company, ['employees.create']);
    grantCompanyPermissions($other, $company, ['employees.create']);

    $this->actingAs($creator)
        ->postJson('/organization/employees/ensure', [
            'name' => 'Creator Idem',
            'idempotency_key' => 'shared-key-iiiiiiii',
        ])
        ->assertOk();

    $this->actingAs($other)
        ->postJson('/organization/employees/ensure', [
            'name' => 'Other Idem',
            'idempotency_key' => 'shared-key-iiiiiiii',
        ])
        ->assertOk();

    expect(Employee::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('refreshing an owned provisional draft via create resume works', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-ok');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Resume Draft',
        'idempotency_key' => 'owner-key-jjjjjjjj',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('mode', 'create')
            ->where('employee.id', $employeeId)
            ->where('employee.name', 'Resume Draft'));
});

test('existing employee relationships survive finalization', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('rels');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.view']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'With Contract',
        'idempotency_key' => 'owner-key-kkkkkkkk',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeId,
    ]);

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'REL-001',
        'name' => 'With Contract',
    ])->assertRedirect();

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toBe('REL-001')
        ->and(EmployeeContract::query()->whereKey($contract->id)->exists())->toBeTrue()
        ->and((int) EmployeeContract::query()->findOrFail($contract->id)->employee_id)->toBe($employeeId);
});

test('department-restricted creator must select a department before finalizing', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('dept-req');
    $this->actingAs($user);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Req',
        'code' => 'MARR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$department->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Needs Dept',
        'idempotency_key' => 'owner-key-llllllll',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'NODEPT-1',
        'name' => 'Needs Dept',
    ])->assertSessionHasErrors('department_id');

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});
