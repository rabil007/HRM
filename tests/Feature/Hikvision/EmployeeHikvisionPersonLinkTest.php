<?php

use App\Models\Employee;
use App\Models\HikvisionPerson;
use App\Models\User;

test('user with permission can link employee to hikvision person from persons page', function () {
    $auth = User::factory()->create();
    $employee = Employee::factory()->forCompany(hikvisionTestCompany())->create();
    $person = HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => 'link-person-1',
        'full_name' => 'Link Person',
        'person_code' => 'EMP100',
    ]);

    grantCompanyPermissions($auth, $employee->company, [
        'hikvision.persons.view',
        'hikvision.persons.link',
    ]);

    $this->actingAs($auth)
        ->from(route('hikvision.persons.index'))
        ->put(route('hikvision.persons.employee.link', $person), [
            'employee_id' => $employee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($employee->fresh()->hikvision_person_id)->toBe($person->id);
});

test('linking hikvision person unlinks previous employee', function () {
    $auth = User::factory()->create();
    $company = hikvisionTestCompany();
    $person = HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => 'relink-person-1',
        'full_name' => 'Shared Person',
    ]);

    $previousEmployee = Employee::factory()->forCompany($company)->create([
        'hikvision_person_id' => $person->id,
    ]);
    $newEmployee = Employee::factory()->forCompany($company)->create();

    grantCompanyPermissions($auth, $company, [
        'hikvision.persons.view',
        'hikvision.persons.link',
    ]);

    $this->actingAs($auth)
        ->put(route('hikvision.persons.employee.link', $person), [
            'employee_id' => $newEmployee->id,
        ])
        ->assertRedirect();

    expect($newEmployee->fresh()->hikvision_person_id)->toBe($person->id)
        ->and($previousEmployee->fresh()->hikvision_person_id)->toBeNull();
});

test('user can unlink employee from hikvision person', function () {
    $auth = User::factory()->create();
    $person = HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => 'unlink-person-1',
        'full_name' => 'Unlink Person',
    ]);
    $employee = Employee::factory()->forCompany(hikvisionTestCompany())->create([
        'hikvision_person_id' => $person->id,
    ]);

    grantCompanyPermissions($auth, $employee->company, [
        'hikvision.persons.view',
        'hikvision.persons.link',
    ]);

    $this->actingAs($auth)
        ->put(route('hikvision.persons.employee.link', $person), [
            'employee_id' => null,
        ])
        ->assertRedirect();

    expect($employee->fresh()->hikvision_person_id)->toBeNull();
});

test('user without link permission cannot update hikvision person employee link', function () {
    $auth = User::factory()->create();
    $employee = Employee::factory()->forCompany(hikvisionTestCompany())->create();
    $person = HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => 'blocked-link-1',
        'full_name' => 'Blocked',
    ]);

    grantCompanyPermissions($auth, $employee->company, ['hikvision.persons.view']);

    $this->actingAs($auth)
        ->put(route('hikvision.persons.employee.link', $person), [
            'employee_id' => $employee->id,
        ])
        ->assertForbidden();
});

test('persons page includes employee link options when permitted', function () {
    $auth = User::factory()->create();
    $employee = Employee::factory()->create();

    grantCompanyPermissions($auth, $employee->company, [
        'hikvision.persons.view',
        'hikvision.persons.link',
    ]);

    $this->actingAs($auth)
        ->get(route('hikvision.persons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees_for_linking', 1)
            ->where('can.link', true),
        );
});
