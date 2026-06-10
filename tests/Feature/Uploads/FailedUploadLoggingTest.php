<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Uploads\FailedUploadLogger;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

test('failed document upload validation is logged globally', function () {
    Event::fake([MessageLogged::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('file');

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['reason'] ?? '') === 'Upload request failed validation.',
    );
});

test('successful document upload is not logged as a failure', function () {
    Event::fake([MessageLogged::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    Event::assertNotDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE,
    );
});

test('failed training certificate upload validation is logged with module context', function () {
    Event::fake([MessageLogged::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TLG',
        'name' => 'Training Log Land',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TLG',
        'name' => 'Training Log Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Log Co',
        'slug' => 'training-log-co',
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
            'employee_no' => 'EMPLOG1',
            'name' => 'Training Log Trainee',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $course = Course::query()->create([
        'name' => 'Logged Training Course',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-01-01',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('certificate');

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['reason'] ?? '') === 'Upload request failed validation.'
            && ($event->context['upload_module'] ?? null) === 'employee_training_certificate',
    );
});

test('failed training bulk certificate upload validation is logged with module context', function () {
    Event::fake([MessageLogged::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TBL',
        'name' => 'Training Bulk Log Land',
        'dial_code' => '+996',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TBL',
        'name' => 'Training Bulk Log Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Bulk Log Co',
        'slug' => 'training-bulk-log-co',
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
            'employee_no' => 'EMPLOG2',
            'name' => 'Bulk Log Trainee',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $course = Course::query()->create([
        'name' => 'Bulk Log Course',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.bulk-store', $employee), [
        'trainings' => [
            [
                'course_id' => $course->id,
                'issue_date' => '2024-01-01',
                'institute_center' => 'MTC',
                'certificate' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ],
        ],
    ])->assertSessionHasErrors('trainings.0.certificate');

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['upload_module'] ?? null) === 'employee_training_certificate',
    );
});

test('training certificate storage failures include employee context', function () {
    Event::fake([MessageLogged::class]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = new UploadedFile(
        __FILE__,
        'broken.pdf',
        'application/pdf',
        UPLOAD_ERR_PARTIAL,
        true,
    );

    expect(fn () => UploadedFileStorage::storePublicly($file, 'employees/1/training-certificates', [
        'disk' => 'public',
        'log_context' => [
            'upload_module' => 'employee_training_certificate',
            'employee_id' => 42,
            'training_index' => 1,
        ],
    ]))->toThrow(RuntimeException::class);

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['upload_module'] ?? null) === 'employee_training_certificate'
            && ($event->context['employee_id'] ?? null) === 42
            && ($event->context['training_index'] ?? null) === 1,
    );
});

test('uploaded file storage failures are logged with file context', function () {
    Event::fake([MessageLogged::class]);

    $file = new UploadedFile(
        __FILE__,
        'broken.pdf',
        'application/pdf',
        UPLOAD_ERR_PARTIAL,
        true,
    );

    expect(fn () => UploadedFileStorage::store($file, 'employee-documents', 'public'))
        ->toThrow(RuntimeException::class);

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['failure_stage'] ?? null) === 'storage'
            && ($event->context['file']['original_name'] ?? null) === 'broken.pdf',
    );
});
