<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('guests cannot create user for employee', function () {
    $employee = Employee::factory()->create();

    $this->post("/organization/employees/{$employee->id}/user", [
        'role_id' => 1,
        'email' => 'new@example.com',
        'name' => 'New User',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('login'));
});

test('users without users.create cannot create user for employee', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    [$company, $employee] = createEmployeeForUserCreationTest();

    grantCompanyPermissions($auth, $company, ['employees.update', 'employees.view']);

    $this->post("/organization/employees/{$employee->id}/user", [
        'role_id' => 1,
        'email' => 'new@example.com',
        'name' => 'New User',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertForbidden();
});

test('cannot create user when employee already has linked user', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    [$company, $employee, $role] = createEmployeeForUserCreationTest(withRole: true);

    $existingUser = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Existing',
        'email' => 'existing@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    $employee->update(['user_id' => $existingUser->id]);

    grantCompanyPermissions($auth, $company, ['users.create', 'employees.update']);

    $this->post("/organization/employees/{$employee->id}/user", [
        'role_id' => $role->id,
        'email' => 'another@example.com',
        'name' => 'Another User',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422);
});

test('password confirmation must match when creating user for employee', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    [$company, $employee, $role] = createEmployeeForUserCreationTest(withRole: true);

    grantCompanyPermissions($auth, $company, ['users.create', 'employees.update']);

    $this->post("/organization/employees/{$employee->id}/user", [
        'role_id' => $role->id,
        'email' => 'new@example.com',
        'name' => 'New User',
        'password' => 'password123',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
});

test('email must be unique per company when creating user for employee', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    [$company, $employee, $role] = createEmployeeForUserCreationTest(withRole: true);

    User::query()->create([
        'company_id' => $company->id,
        'name' => 'Taken',
        'email' => 'taken@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    grantCompanyPermissions($auth, $company, ['users.create', 'employees.update']);

    $this->post("/organization/employees/{$employee->id}/user", [
        'role_id' => $role->id,
        'email' => 'taken@example.com',
        'name' => 'New User',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

test('authenticated users can create and link user for employee', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    [$company, $employee, $role] = createEmployeeForUserCreationTest(withRole: true);

    grantCompanyPermissions($auth, $company, ['users.create', 'employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->post("/organization/employees/{$employee->id}/user", [
            'role_id' => $role->id,
            'email' => 'employee.user@example.com',
            'name' => 'Employee User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertRedirect("/organization/employees/{$employee->id}")
        ->assertSessionHas('success');

    $employee->refresh();

    expect($employee->user_id)->not->toBeNull();

    $createdUser = User::query()->find($employee->user_id);

    expect($createdUser)->not->toBeNull()
        ->and($createdUser->email)->toBe('employee.user@example.com')
        ->and($createdUser->name)->toBe('Employee User')
        ->and($createdUser->company_id)->toBe($company->id);

    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $company->id,
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $createdUser->id,
    ]);
});

/**
 * @return array{0: Company, 1: Employee, 2?: Role}
 */
function createEmployeeForUserCreationTest(bool $withRole = false): array
{
    $country = Country::query()->create([
        'code' => 'EUC',
        'name' => 'EU Create Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'EUC',
        'name' => 'EU Create Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Create User Co',
        'slug' => 'create-user-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-USER-01',
            'name' => 'Profile Employee',
            'work_email' => 'work@example.com',
            'personal_email' => 'personal@example.com',
            'status' => 'active',
        ]);

    if (! $withRole) {
        return [$company, $employee];
    }

    $role = Role::query()->firstOrCreate([
        'company_id' => $company->id,
        'name' => 'Staff',
        'guard_name' => 'web',
    ]);

    return [$company, $employee, $role];
}
