<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\ContractSalaryComponent;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVaccination;
use App\Models\EmployeeWorkExperience;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function makeEmployeeProfileActivityFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'EAL',
        'name' => 'Employee Activity Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'EAL',
        'name' => 'Employee Activity Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Employee Activity Co',
        'slug' => 'employee-activity-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'country');
}

test('activity log is recorded for employee training creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $course = Course::query()->create([
        'name' => 'Activity Training Course '.uniqid(),
        'is_active' => true,
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $course->id,
        'institute_center' => 'Activity Academy',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeTraining::class,
        'subject_id' => $training->id,
    ]);

    $activity = Activity::query()
        ->where('subject_type', EmployeeTraining::class)
        ->where('subject_id', $training->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($company->id);
});

test('activity log is recorded for employee contract creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $contract = EmployeeContract::factory()->for($company)->for($employee)->create([
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeContract::class,
        'subject_id' => $contract->id,
    ]);
});

test('activity log is recorded for employee bank account creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'country' => $country] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $bank = Bank::query()->create([
        'name' => 'Activity Bank',
        'country_id' => $country->id,
        'is_active' => true,
    ]);

    $account = EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE000011112222',
        'account_name' => 'Activity Account',
        'is_primary' => true,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeBankAccount::class,
        'subject_id' => $account->id,
    ]);
});

test('activity log is recorded for employee education creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $education = EmployeeEducationQualification::factory()->forEmployee($employee)->create([
        'certificate' => 'BSc Maritime Studies',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeEducationQualification::class,
        'subject_id' => $education->id,
    ]);
});

test('activity log is recorded for employee work experience creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $experience = EmployeeWorkExperience::factory()->forEmployee($employee)->create([
        'company_name' => 'Activity Shipping',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeWorkExperience::class,
        'subject_id' => $experience->id,
    ]);
});

test('activity log is recorded for employee sea service creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $seaService = EmployeeSeaService::factory()->forEmployee($employee)->create();

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeSeaService::class,
        'subject_id' => $seaService->id,
    ]);
});

test('activity log is recorded for employee language creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $language = EmployeeLanguage::factory()->forEmployee($employee)->create([
        'language_name' => 'English',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeLanguage::class,
        'subject_id' => $language->id,
    ]);
});

test('activity log is recorded for employee vaccination creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $vaccination = EmployeeVaccination::factory()->forEmployee($employee)->create([
        'vaccination_name' => 'Yellow Fever',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => EmployeeVaccination::class,
        'subject_id' => $vaccination->id,
    ]);
});

test('activity log is recorded for contract salary component creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeProfileActivityFixtures();
    $this->actingAs($user);

    $contract = EmployeeContract::factory()->for($company)->for($employee)->create([
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $component = ContractSalaryComponent::factory()->for($contract, 'contract')->create([
        'company_id' => $company->id,
        'amount' => 2500,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => ContractSalaryComponent::class,
        'subject_id' => $component->id,
    ]);
});
