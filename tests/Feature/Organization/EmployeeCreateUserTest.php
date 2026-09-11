<?php

use App\Mail\UserInvitationMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

test('guests cannot create user for employee', function () {
    $employee = Employee::factory()->create();

    $this->post("/organization/employees/{$employee->id}/user", [
        'role_id' => 1,
        'email' => 'new@example.com',
        'name' => 'New User',
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
    ])->assertStatus(422);
});

test('authenticated users can invite and link user for employee', function () {
    Mail::fake();

    $auth = User::factory()->create();
    $this->actingAs($auth);

    [$company, $employee, $role] = createEmployeeForUserCreationTest(withRole: true);

    grantCompanyPermissions($auth, $company, ['users.create', 'employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->post("/organization/employees/{$employee->id}/user", [
            'role_id' => $role->id,
            'email' => 'employee.user@example.com',
            'name' => 'Employee User',
        ])
        ->assertRedirect("/organization/employees/{$employee->id}")
        ->assertSessionHas('success', 'Invitation sent successfully.');

    $employee->refresh();

    expect($employee->user_id)->toBeNull();

    $invitation = UserInvitation::query()
        ->where('email', 'employee.user@example.com')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->company_id)->toBe($company->id)
        ->and($invitation->employee_id)->toBe($employee->id)
        ->and($invitation->role_id)->toBe($role->id)
        ->and($invitation->name)->toBe('Employee User');

    Mail::assertQueued(UserInvitationMail::class, function (UserInvitationMail $mail) use ($invitation) {
        return $mail->hasTo('employee.user@example.com')
            && hash('sha256', $mail->token) === $invitation->token_hash;
    });
});

test('employee user invitation can use an email already owned by another company', function () {
    Mail::fake();

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
    ])->assertSessionHasNoErrors();

    expect(UserInvitation::query()->where('email', 'taken@example.com')->exists())->toBeTrue()
        ->and($employee->fresh()->user_id)->toBeNull();
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
