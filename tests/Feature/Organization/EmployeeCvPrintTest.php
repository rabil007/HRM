<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\Employees\Services\AdnocSeafarerCvData;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

test('guests cannot print employee cv', function () {
    $employee = Employee::factory()->create();

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertRedirect(route('login'));
});

test('authenticated users can open printable adnoc seafarer cv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CVP',
        'name' => 'CV Print Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CVP',
        'name' => 'CV Print Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Maritime Co',
        'slug' => 'maritime-co',
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
            'employee_no' => 'EMP0099',
            'name' => 'Captain Ahmed',
            'work_email' => 'ahmed@example.com',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('alt="ADNOC"', false)
        ->assertSee('data:image/png;base64,', false)
        ->assertDontSee('ADNOC Logistics &amp; Services', false)
        ->assertSee('Standard CV Form (Seafarer)', false)
        ->assertSee('SECTION 7 - LAUNGAGES KNOWN', false)
        ->assertSee('SECTION 11 - REFERENCES', false)
        ->assertSee('SECTION 12 - DECLARATION', false)
        ->assertSee('SECTION 13 - CV EVALUATION (FOR OFFICE USE ONLY)', false)
        ->assertSee('HRO REPRESENTATIVE', false)
        ->assertSee('FRM-HRA-RMP-032- Rev. 00', false)
        ->assertSee('SECTION 1 - PERSONAL DATA', false)
        ->assertSee('CAPTAIN AHMED', false)
        ->assertSee('View A4 PDF', false);
});

test('adnoc cv shows company logo on the left when company has a logo', function () {
    Storage::fake('public');

    $logoPath = 'company-logos/test-logo.png';
    Storage::disk('public')->put(
        $logoPath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
    );

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CLG',
        'name' => 'Company Logo Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLG',
        'name' => 'Company Logo Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Logo Co',
        'slug' => 'logo-co',
        'logo' => $logoPath,
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
            'name' => 'Logo Seafarer',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('alt="Company"', false)
        ->assertSee('head-logo-cell--left', false)
        ->assertSee('data:image/png;base64,', false);
});

test('adnoc cv shows application main logo on the left when branding logo is configured', function () {
    Storage::fake('public');

    $logoPath = 'settings/main_logo-test.png';
    Storage::disk('public')->put(
        $logoPath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
    );

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::MainLogo],
        ['value' => $logoPath, 'type' => 'file'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'MLG',
        'name' => 'Main Logo Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'MLG',
        'name' => 'Main Logo Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Branding Co',
        'slug' => 'branding-co',
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
            'name' => 'Branded Seafarer',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('alt="Company"', false)
        ->assertSee('data:image/png;base64,', false);
});

test('authenticated users can download adnoc cv as pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'PDF',
        'name' => 'PDF Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PDF',
        'name' => 'PDF Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'OMS',
        'slug' => 'oms',
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
            'employee_no' => 'EMP0200',
            'name' => 'Test Seafarer',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv?format=pdf&inline=1")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('adnoc cv includes sea service rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SEA',
        'name' => 'Sea Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SEA',
        'name' => 'Sea Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Sea Co',
        'slug' => 'sea-co',
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
            'name' => 'Sea Captain',
            'status' => 'active',
        ]);

    EmployeeSeaService::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_name' => 'MV Test Vessel',
        'start_date' => '2024-01-01',
        'end_date' => '2024-06-01',
        'total_months' => 5,
        'total_days' => 0,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('MV Test Vessel', false)
        ->assertSee('SECTION 10 - SUMMARY OF WORK EXPERIENCE', false);
});

test('adnoc cv includes stcw training rows from employee training', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRN',
        'name' => 'Training Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRN',
        'name' => 'Training Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Co',
        'slug' => 'training-co-cv',
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
            'name' => 'Trained Seafarer',
            'status' => 'active',
        ]);

    $course = Course::query()->create([
        'name' => 'STCW Fire Fighting',
        'is_active' => true,
    ]);

    EmployeeTraining::factory()
        ->forEmployee($employee)
        ->create([
            'course_id' => $course->id,
            'issue_date' => '2024-03-15',
            'expiry_date' => '2029-03-15',
            'institute_center' => 'Maritime Training Center',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('STCW FIRE FIGHTING', false)
        ->assertSee('MARITIME TRAINING CENTER', false)
        ->assertSee('SECTION 6 - STCW/OTHER TRAINING/PROFESSIONAL COURSES DETAILS', false)
        ->assertDontSee('No training records', false);
});

test('adnoc cv shows message when employee has no stcw training', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'NTN',
        'name' => 'No Training Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'NTN',
        'name' => 'No Training Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'No Training Co',
        'slug' => 'no-training-co',
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
            'name' => 'No Training Seafarer',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('No training records', false);
});

test('adnoc cv paginates long stcw training lists in pdf mode', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'STC',
        'name' => 'STCW Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'STC',
        'name' => 'STCW Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'STCW Co',
        'slug' => 'stcw-co',
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
            'name' => 'Many Courses',
            'status' => 'active',
        ]);

    foreach (range(1, 20) as $i) {
        $course = Course::query()->create([
            'name' => "Training Course {$i}",
            'is_active' => true,
        ]);

        EmployeeTraining::factory()
            ->forEmployee($employee)
            ->create([
                'course_id' => $course->id,
                'issue_date' => '2024-01-01',
                'expiry_date' => '2029-01-01',
                'institute_center' => "Center {$i}",
            ]);
    }

    grantCompanyPermissions($user, $company, ['employees.view']);

    $data = AdnocSeafarerCvData::for($employee, $company->id);
    $data['is_pdf'] = true;
    $data['printable'] = false;

    $html = view('employees.adnoc-cv', $data)->render();

    expect($html)
        ->toContain('cv-head--repeat')
        ->toContain('cv-page-footer')
        ->and(substr_count($html, 'cv-head--repeat'))->toBeGreaterThanOrEqual(2);
});

test('adnoc cv closing sections render after many sea service rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'MNY',
        'name' => 'Many Sea Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'MNY',
        'name' => 'Many Sea Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Many Sea Co',
        'slug' => 'many-sea-co',
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
            'name' => 'Long Career',
            'status' => 'active',
        ]);

    foreach (range(1, 20) as $i) {
        EmployeeSeaService::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'vessel_name' => "MV Vessel {$i}",
            'start_date' => '2020-01-01',
            'end_date' => '2020-06-01',
            'total_months' => 5,
            'total_days' => 0,
            'sort_order' => $i,
        ]);
    }

    grantCompanyPermissions($user, $company, ['employees.view']);

    $html = $this->get("/organization/employees/{$employee->id}/cv")
        ->assertSuccessful()
        ->assertSee('SECTION 12 - DECLARATION', false)
        ->assertSee('PLEASE ATTACH ALL CERTIFICATES AND DOCUMENTS.', false)
        ->assertSee('SECTION 13 - CV EVALUATION (FOR OFFICE USE ONLY)', false)
        ->getContent();

    expect(substr_count($html, 'SECTION 11 - REFERENCES'))->toBe(1);
});

test('users cannot print cv for employees in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'OTH',
        'name' => 'Other Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OTH',
        'name' => 'Other Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Allowed Co',
        'slug' => 'allowed-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($otherCompany)
        ->create([
            'name' => 'Hidden Employee',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/cv")
        ->assertNotFound();
});
