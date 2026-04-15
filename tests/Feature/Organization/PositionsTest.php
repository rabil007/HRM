<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('guests cannot access positions page', function () {
    $this->get('/organization/positions')->assertRedirect(route('login'));
});

test('authenticated users can view positions page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view']);

    $this->get('/organization/positions')->assertOk();
});

test('authenticated users can view a position details page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'min_salary' => 1000,
        'max_salary' => 2000,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view']);

    $this->get("/organization/positions/{$position->id}")->assertOk();
});

test('authenticated users can create, update, and delete a position', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.create', 'positions.update', 'positions.delete', 'positions.view']);

    $this->post('/organization/positions', [
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'min_salary' => 1000,
        'max_salary' => 2000,
        'status' => 'active',
    ])->assertRedirect('/organization/positions');

    $positionId = Position::query()
        ->where('company_id', $company->id)
        ->where('title', 'Software Engineer')
        ->value('id');

    expect($positionId)->not->toBeNull();

    $this->put("/organization/positions/{$positionId}", [
        'department_id' => $department->id,
        'title' => 'Senior Software Engineer',
        'grade' => 'G6',
        'min_salary' => 2000,
        'max_salary' => 4000,
        'status' => 'inactive',
    ])->assertRedirect('/organization/positions');

    $this->assertDatabaseHas('positions', [
        'id' => $positionId,
        'title' => 'Senior Software Engineer',
        'grade' => 'G6',
        'status' => 'inactive',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Position::class)
        ->where('subject_id', $positionId)
        ->where('event', 'updated')
        ->latest('id')
        ->first();
    expect($activity)->not->toBeNull();

    $this->delete("/organization/positions/{$positionId}")->assertRedirect('/organization/positions');
    $this->assertDatabaseMissing('positions', ['id' => $positionId]);
});

test('authenticated users can export positions as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Position::query()->create([
        'company_id' => $company->id,
        'title' => 'HR Specialist',
        'grade' => 'H1',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view', 'positions.export']);

    $csv = $this->get('/organization/positions/export?format=csv&search=H1');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/positions/export?format=xlsx&search=H1');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/positions/export?format=pdf&search=H1');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('authenticated users can toggle position status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'min_salary' => 1000,
        'max_salary' => 2000,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.update']);

    $this->put("/organization/positions/{$position->id}/status", [
        'status' => 'inactive',
    ])->assertRedirect('/organization/positions');

    $this->assertDatabaseHas('positions', [
        'id' => $position->id,
        'status' => 'inactive',
    ]);
});
