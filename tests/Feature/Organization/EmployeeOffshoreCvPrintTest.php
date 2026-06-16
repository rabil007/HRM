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

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_name' => 'Najeeb 3000',
            'vessel_type_id' => $vesselType->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'start_date' => '2023-01-10',
            'end_date' => '2023-12-20',
            'total_months' => 11,
            'total_days' => 10,
            'grt' => 4500,
            'bhp' => 12000,
            'is_offshore' => true,
        ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_name' => 'Onshore Vessel',
            'is_offshore' => false,
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
        ->assertDontSee('Onshore Vessel', false);
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

test('offshore cv data only includes offshore sea service rows', function () {
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

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_name' => 'Offshore Alpha',
            'is_offshore' => true,
        ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_name' => 'Seagoing Beta',
            'is_offshore' => false,
        ]);

    $data = OffshoreCvData::for($employee, $company->id);

    expect($data['offshore_projects'])->toHaveCount(1)
        ->and($data['offshore_projects'][0]['vessel_name'])->toBe('Offshore Alpha');
});
