<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{user: User, company: Company, employee: Employee}
 */
function makeSalaryPrintPermissionFixtures(): array
{
    $user = User::factory()->create();
    $code = 'SP'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Salary Print Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Salary Print Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Salary Print Co',
        'slug' => 'salary-print-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Print Employee',
    ]);

    return compact('user', 'company', 'employee');
}

test('employees view alone cannot print salary certificate or declaration', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeSalaryPrintPermissionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.salary-certificate', $employee))
        ->assertForbidden();
    $this->get(route('organization.employees.salary-declaration', $employee))
        ->assertForbidden();
});

test('salary certificate print permission allows certificate and forbids declaration', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeSalaryPrintPermissionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.salary_certificate.print',
    ]);

    $this->get(route('organization.employees.salary-certificate', $employee))
        ->assertSuccessful();
    $this->get(route('organization.employees.salary-declaration', $employee))
        ->assertForbidden();
});

test('salary declaration print permission allows declaration and forbids certificate', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeSalaryPrintPermissionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.salary_declaration.print',
    ]);

    $this->get(route('organization.employees.salary-declaration', $employee))
        ->assertSuccessful();
    $this->get(route('organization.employees.salary-certificate', $employee))
        ->assertForbidden();
});

test('employee profile can flags expose salary print permissions', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeSalaryPrintPermissionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.salary_certificate.print',
    ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('can.salary_certificate_print', true)
            ->where('can.salary_declaration_print', false));
});

test('permissions seeder registers salary print permissions', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    expect(Permission::query()->where('name', 'employees.salary_certificate.print')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'employees.salary_declaration.print')->exists())->toBeTrue();
});

test('salary print permissions are granted to existing employee viewer roles', function () {
    $migration = require database_path('migrations/2026_07_28_161152_add_employee_salary_print_permissions.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->down();

    ['user' => $user, 'company' => $company] = makeSalaryPrintPermissionFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    expect($role->fresh()->hasPermissionTo('employees.salary_certificate.print'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('employees.salary_declaration.print'))->toBeTrue();
});
