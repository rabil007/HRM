<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Employees\Services\OffshoreCvData;

test('guests cannot print employee offshore cv', function () {
    $employee = Employee::factory()->create();

    $this->get("/organization/employees/{$employee->id}/offshore-cv")
        ->assertRedirect(route('login'));
});

test('authenticated users can open printable offshore cv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'OCV',
        'name' => 'Offshore CV Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OCV',
        'name' => 'Offshore CV Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Offshore Marine Co',
        'slug' => 'offshore-marine-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $visaType = CompanyVisaType::query()->create([
        'name' => 'Employment Visa',
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Rigger',
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'name' => 'Ahmed Hassan',
            'phone' => '+971 50 123 4567',
            'work_email' => 'ahmed.hassan@example.com',
            'rank_id' => $rank->id,
            'company_visa_type_id' => $visaType->id,
            'status' => 'active',
        ]);

    $course = Course::query()->create([
        'name' => 'BOSIET',
        'is_active' => true,
    ]);

    EmployeeTraining::factory()
        ->forEmployee($employee)
        ->create([
            'course_id' => $course->id,
            'country_id' => $country->id,
            'expiry_date' => '2027-06-15',
        ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Jack-Up Barge',
        'is_active' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'ADNOC Offshore',
        'is_active' => true,
    ]);

    $offshoreVessel = Vessel::query()->create([
        'name' => 'Najeeb 3000',
        'vessel_type_id' => $vesselType->id,
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $offshoreVessel->id,
            'vessel_type_id' => $vesselType->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'start_date' => '2023-01-10',
            'end_date' => '2023-12-20',
            'total_months' => 11,
            'total_days' => 10,
        ]);

    $onshoreVessel = Vessel::query()->create([
        'name' => 'Onshore Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $onshoreVessel->id,
            'vessel_type_id' => $vesselType->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/offshore-cv")
        ->assertSuccessful()
        ->assertSee('AHMED HASSAN', false)
        ->assertSee('Employment Visa', false)
        ->assertSee('Professional Summary', false)
        ->assertSee('Core Competencies', false)
        ->assertSee('Technical Certifications', false)
        ->assertSee('Offshore Project History', false)
        ->assertSee('BOSIET', false)
        ->assertSee('Najeeb 3000', false)
        ->assertSee('ADNOC Offshore', false)
        ->assertSee('Onshore Vessel', false)
        ->assertSee('Total experience in the applied rank (in years)', false)
        ->assertSee('Offshore experience (in years)', false);
});

test('authenticated users can download offshore cv as pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'OCP',
        'name' => 'Offshore CV PDF Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OCP',
        'name' => 'Offshore CV PDF Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'PDF Offshore Co',
        'slug' => 'pdf-offshore-co',
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
            'name' => 'PDF Offshore Worker',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/offshore-cv?format=pdf&inline=1")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('offshore cv data includes all sea service rows in project history', function () {
    $country = Country::query()->create([
        'code' => 'OCD',
        'name' => 'Offshore CV Data Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OCD',
        'name' => 'Offshore CV Data Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Data Offshore Co',
        'slug' => 'data-offshore-co',
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
            'name' => 'Data Worker',
            'status' => 'active',
        ]);

    $vessel = Vessel::query()->create([
        'name' => 'Offshore Alpha',
        'vessel_type_id' => VesselType::query()->create(['name' => 'Type Offshore Alpha', 'is_active' => true])->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $vessel->id,
            'vessel_type_id' => $vessel->vessel_type_id,
        ]);

    $vessel = Vessel::query()->create([
        'name' => 'Seagoing Beta',
        'vessel_type_id' => VesselType::query()->create(['name' => 'Type Seagoing Beta', 'is_active' => true])->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $vessel->id,
            'vessel_type_id' => $vessel->vessel_type_id,
        ]);

    $data = OffshoreCvData::for($employee, $company->id);

    expect($data['offshore_projects'])->toHaveCount(2)
        ->and(collect($data['offshore_projects'])->pluck('vessel_name')->all())
        ->toContain('Offshore Alpha', 'Seagoing Beta')
        ->and($data['experience_offshore_ymd'])->not->toBe('0Y/0M/0D');
});

test('offshore cv applied rank and offshore experience use different filters', function () {
    $country = Country::query()->create([
        'code' => 'OCR',
        'name' => 'Offshore CV Rank Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OCR',
        'name' => 'Offshore CV Rank Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Rank Offshore Co',
        'slug' => 'rank-offshore-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $masterRank = Rank::query()->create(['name' => 'Master', 'is_active' => true]);
    $shadowRank = Rank::query()->create(['name' => 'Shadow Master', 'is_active' => true]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'name' => 'Rank Worker',
            'rank_id' => $masterRank->id,
            'status' => 'active',
        ]);

    $masterVessel = Vessel::query()->create([
        'name' => 'Master Vessel',
        'vessel_type_id' => VesselType::query()->create(['name' => 'Master Type', 'is_active' => true])->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $masterVessel->id,
            'vessel_type_id' => $masterVessel->vessel_type_id,
            'rank_id' => $masterRank->id,
            'start_date' => null,
            'end_date' => null,
            'total_months' => 11,
            'total_days' => 30,
        ]);

    $shadowVessel = Vessel::query()->create([
        'name' => 'Shadow Vessel',
        'vessel_type_id' => VesselType::query()->create(['name' => 'Shadow Type', 'is_active' => true])->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $shadowVessel->id,
            'vessel_type_id' => $shadowVessel->vessel_type_id,
            'rank_id' => $shadowRank->id,
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
            'total_months' => 12,
            'total_days' => 0,
        ]);

    $data = OffshoreCvData::for($employee, $company->id);

    expect($data['experience_rank_ymd'])->toBe('1Y/0M/0D')
        ->and($data['experience_offshore_ymd'])->toBe('1Y/0M/0D');
});

test('offshore cv applied rank is zero when no sea service matches employee rank', function () {
    $country = Country::query()->create([
        'code' => 'OCZ',
        'name' => 'Offshore CV Zero Rank Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OCZ',
        'name' => 'Offshore CV Zero Rank Currency',
        'symbol' => 'Z$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Zero Rank Offshore Co',
        'slug' => 'zero-rank-offshore-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $masterRank = Rank::query()->create(['name' => 'Master', 'is_active' => true]);
    $shadowRank = Rank::query()->create(['name' => 'Shadow Master', 'is_active' => true]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'name' => 'Zero Rank Worker',
            'rank_id' => $masterRank->id,
            'status' => 'active',
        ]);

    $shadowVessel = Vessel::query()->create([
        'name' => 'Shadow Vessel',
        'vessel_type_id' => VesselType::query()->create(['name' => 'Zero Rank Offshore Type', 'is_active' => true])->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_id' => $shadowVessel->id,
            'vessel_type_id' => $shadowVessel->vessel_type_id,
            'rank_id' => $shadowRank->id,
            'start_date' => null,
            'end_date' => null,
            'total_months' => 6,
            'total_days' => 15,
        ]);

    $data = OffshoreCvData::for($employee, $company->id);

    expect($data['experience_rank_ymd'])->toBe('0Y/0M/0D')
        ->and($data['experience_offshore_ymd'])->toBe('0Y/6M/15D');
});
