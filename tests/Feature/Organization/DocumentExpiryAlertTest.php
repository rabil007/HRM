<?php

use App\Jobs\SendDocumentExpiryAlertJob;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\Department;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Models\User;
use App\Services\DocumentExpiryAlertService;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\MigrateLegacyDocumentExpiryAlertRecipients;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['documents.expiry_alert_days' => 30]);
    configureDocumentExpiryAlertTemplate();
});

test('dispatch command queues job only when pending documents exist', function () {
    Queue::fake();
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    $this->artisan('documents:dispatch-expiry-alerts', ['--company' => $company->id])
        ->assertSuccessful();

    Queue::assertPushed(SendDocumentExpiryAlertJob::class, fn (SendDocumentExpiryAlertJob $job) => $job->companyId === $company->id);

    Mail::assertSentCount(0);
});

test('dispatch command does nothing when no enabled routing rule has TO recipients', function () {
    Queue::fake();
    configureDocumentExpiryAlertTemplate();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    $this->artisan('documents:dispatch-expiry-alerts', ['--company' => $company->id])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('consolidated expiry alert email is sent once with all pending documents sorted by employee name', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $employeeB = Employee::factory()->create([
        'company_id' => $company->id,
        'name' => 'Zara Khan',
        'employee_no' => 'EMP-002',
    ]);

    $employeeC = Employee::factory()->create([
        'company_id' => $company->id,
        'name' => 'Ahmed Ali',
        'employee_no' => 'EMP-001',
    ]);

    $docC = createEmployeePdfDocument(
        $company->id,
        $employeeC->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employeeC->id}/passport/c.pdf",
        'Passport C.pdf',
    );
    $docC->update(['expiry_date' => '2026-06-20']);

    $docB = createEmployeePdfDocument(
        $company->id,
        $employeeB->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employeeB->id}/passport/z.pdf",
        'Visa Z.pdf',
    );
    $docB->update(['expiry_date' => '2026-06-25']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) use ($employeeC) {
        return $mail->hasTo('hr@example.com')
            && $mail->hasCc('manager@example.com')
            && ! $mail->hasCc('hr@example.com')
            && $mail->envelope()->subject === 'Employee Document Expiry Alert — 2 document(s) require attention'
            && count($mail->rows) === 2
            && $mail->rows[0]['employee_name'] === 'Ahmed Ali'
            && $mail->rows[0]['employee_id'] === 'EMP-001'
            && $mail->rows[0]['folder_url'] === route('organization.documents.employee', $employeeC)
            && $mail->rows[1]['employee_name'] === 'Zara Khan';
    });

    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(2);
});

test('expiry alert email html includes view button to employee document folder', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) use ($employee) {
        $html = $mail->render();
        $folderUrl = route('organization.documents.employee', $employee);

        return str_contains($html, 'Action')
            && str_contains($html, '>View</a>')
            && str_contains($html, $folderUrl);
    });
});

test('second run does not resend alert for the same document and expiry date', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-25']);

    $service = app(DocumentExpiryAlertService::class);
    $service->sendForCompany($company->id);

    Mail::assertSentCount(1);

    Carbon::setTestNow('2026-06-16');
    $service->sendForCompany($company->id);

    Mail::assertSentCount(1);
    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(1);
});

test('expiry date change allows a new alert when the new date enters the window', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-25']);

    $service = app(DocumentExpiryAlertService::class);
    $service->sendForCompany($company->id);

    $doc->update(['expiry_date' => '2026-08-01']);

    Carbon::setTestNow('2026-07-05');
    $service->sendForCompany($company->id);

    Mail::assertSentCount(2);

    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(2)
        ->and(EmployeeDocumentExpiryAlert::query()->orderBy('expiry_date_at_alert_time')->pluck('expiry_date_at_alert_time')->map->toDateString()->all())
        ->toBe(['2026-06-25', '2026-08-01']);
});

test('successful expiry alert is logged to activity', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('expiry_alert_sent')
        ->and($activity->log_name)->toBe('documents')
        ->and($activity->properties->get('document_count'))->toBe(1)
        ->and($activity->properties->get('recipient'))->toBe('hr@example.com');
});

test('failed expiry alert is logged to activity', function () {
    ['company' => $company] = makeDocumentFixtures();

    app(DocumentExpiryAlertService::class)->logFailure(
        $company,
        new RuntimeException('SMTP unavailable'),
    );

    $activity = Activity::query()->where('event', 'expiry_alert_failed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('documents');
});

test('selected document types only include matching documents in the rule email', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $medicalType = DocumentType::query()->firstOrCreate(
        ['title' => 'Medical Certificate'],
        ['is_active' => true],
    );

    createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Identity only',
        'all_document_types' => false,
        'document_type_ids' => [$passportType->id],
        'to_emails' => ['identity@example.com'],
        'cc_emails' => [],
    ]);

    $passport = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $passport->update(['expiry_date' => '2026-06-20']);

    $medical = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $medicalType->id,
        "employee-documents/{$company->id}/{$employee->id}/medical/a.pdf",
        'Medical.pdf',
    );
    $medical->update(['expiry_date' => '2026-06-18']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) {
        return $mail->hasTo('identity@example.com')
            && count($mail->rows) === 1
            && str_contains($mail->rows[0]['document_name'], 'Passport');
    });

    Mail::assertSentCount(1);
});

test('restricted internal recipient only receives visible employees in the rule email', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $visibleEmployee, 'passportType' => $passportType] = makeDocumentFixtures();

    $departmentId = (int) $visibleEmployee->department_id;

    $hiddenDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Dept',
        'code' => 'HID-'.uniqid(),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $hiddenEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $hiddenDepartment->id,
        'name' => 'Hidden Person',
        'employee_no' => 'HID-001',
        'status' => 'active',
    ]);

    $restrictedUser = User::factory()->create([
        'email' => 'restricted@example.com',
        'status' => 'active',
    ]);
    grantCompanyPermissions($restrictedUser, $company, ['documents.view']);
    restrictTestRoleEmployeeVisibility($restrictedUser, $company, [$departmentId]);

    createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$restrictedUser->id],
        'to_emails' => [],
        'cc_emails' => [],
    ]);

    $visibleDoc = createEmployeePdfDocument(
        $company->id,
        $visibleEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$visibleEmployee->id}/passport/a.pdf",
        'Visible.pdf',
    );
    $visibleDoc->update(['expiry_date' => '2026-06-20']);

    $hiddenDoc = createEmployeePdfDocument(
        $company->id,
        $hiddenEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$hiddenEmployee->id}/passport/b.pdf",
        'Hidden.pdf',
    );
    $hiddenDoc->update(['expiry_date' => '2026-06-18']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) {
        return $mail->hasTo('restricted@example.com')
            && count($mail->rows) === 1
            && $mail->rows[0]['employee_name'] === 'Test Employee'
            && collect($mail->rows)->pluck('employee_name')->doesntContain('Hidden Person');
    });
});

test('multiple rules receive separate matching summaries and independent deduplication', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $medicalType = DocumentType::query()->firstOrCreate(
        ['title' => 'Medical Certificate'],
        ['is_active' => true],
    );

    $identityRule = createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Identity',
        'all_document_types' => false,
        'document_type_ids' => [$passportType->id],
        'to_emails' => ['identity@example.com'],
        'cc_emails' => [],
    ]);

    $crewRule = createDocumentExpiryNotificationRule($company->id, [
        'name' => 'Crew',
        'all_document_types' => false,
        'document_type_ids' => [$medicalType->id],
        'to_emails' => ['crew@example.com'],
        'cc_emails' => [],
    ]);

    $passport = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $passport->update(['expiry_date' => '2026-06-20']);

    $medical = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $medicalType->id,
        "employee-documents/{$company->id}/{$employee->id}/medical/a.pdf",
        'Medical.pdf',
    );
    $medical->update(['expiry_date' => '2026-06-18']);

    $service = app(DocumentExpiryAlertService::class);
    $service->sendForCompany($company->id);

    Mail::assertSentCount(2);

    Mail::assertSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('identity@example.com') && count($mail->rows) === 1);
    Mail::assertSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('crew@example.com') && count($mail->rows) === 1);

    expect(EmployeeDocumentExpiryAlert::query()->where('notification_rule_id', $identityRule->id)->count())->toBe(1)
        ->and(EmployeeDocumentExpiryAlert::query()->where('notification_rule_id', $crewRule->id)->count())->toBe(1);

    $service->sendForCompany($company->id);

    Mail::assertSentCount(2);
});

test('successful batch is recorded when a later visibility batch fails and is not resent', function () {
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $marineEmployee, 'passportType' => $passportType] = makeDocumentFixtures();
    $marineDepartmentId = (int) $marineEmployee->department_id;

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF-'.uniqid(),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $officeEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $officeDepartment->id,
        'name' => 'Office Person',
        'employee_no' => 'OFF-001',
        'status' => 'active',
    ]);

    $hr = User::factory()->create(['email' => 'hr-manager@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view'], 'expiry-hr-role');

    $crewing = User::factory()->create(['email' => 'crewing@example.com', 'status' => 'active']);
    grantCompanyPermissions($crewing, $company, ['documents.view'], 'expiry-crewing-role');
    restrictTestRoleEmployeeVisibility($crewing, $company, [$marineDepartmentId], 'expiry-crewing-role');

    createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$hr->id],
        'to_emails' => [],
        'cc_user_ids' => [$crewing->id],
        'cc_emails' => [],
    ]);

    $marineDoc = createEmployeePdfDocument(
        $company->id,
        $marineEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$marineEmployee->id}/passport/m.pdf",
        'Marine.pdf',
    );
    $marineDoc->update(['expiry_date' => '2026-06-20']);

    $officeDoc = createEmployeePdfDocument(
        $company->id,
        $officeEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$officeEmployee->id}/passport/o.pdf",
        'Office.pdf',
    );
    $officeDoc->update(['expiry_date' => '2026-06-18']);

    $sendCount = 0;
    $failOnSendNumber = 2;

    Mail::shouldReceive('to')->andReturnUsing(function () use (&$sendCount, &$failOnSendNumber) {
        $sendCount++;
        $pending = Mockery::mock();
        $pending->shouldReceive('cc')->andReturnSelf();

        if ($sendCount === $failOnSendNumber) {
            $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP batch failure'));
        } else {
            $pending->shouldReceive('send')->once()->andReturnNull();
        }

        return $pending;
    });

    $service = app(DocumentExpiryAlertService::class);

    expect(fn () => $service->sendForCompany($company->id))
        ->toThrow(RuntimeException::class, 'SMTP batch failure');

    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(2)
        ->and($sendCount)->toBe(2);

    $failOnSendNumber = -1;
    $service->sendForCompany($company->id);

    expect($sendCount)->toBe(3)
        ->and(EmployeeDocumentExpiryAlert::query()->count())->toBe(3);
});

test('unrestricted TO and restricted CC receive privacy-safe separate summaries', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $marineEmployee, 'passportType' => $passportType] = makeDocumentFixtures();
    $marineDepartmentId = (int) $marineEmployee->department_id;

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF-'.uniqid(),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $officeEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $officeDepartment->id,
        'name' => 'Office Person',
        'employee_no' => 'OFF-001',
        'status' => 'active',
    ]);

    $hr = User::factory()->create(['email' => 'hr-manager@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view'], 'expiry-hr-role');

    $crewing = User::factory()->create(['email' => 'crewing@example.com', 'status' => 'active']);
    grantCompanyPermissions($crewing, $company, ['documents.view'], 'expiry-crewing-role');
    restrictTestRoleEmployeeVisibility($crewing, $company, [$marineDepartmentId], 'expiry-crewing-role');

    createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$hr->id],
        'to_emails' => [],
        'cc_user_ids' => [$crewing->id],
        'cc_emails' => [],
    ]);

    $marineDoc = createEmployeePdfDocument(
        $company->id,
        $marineEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$marineEmployee->id}/passport/m.pdf",
        'Marine.pdf',
    );
    $marineDoc->update(['expiry_date' => '2026-06-20']);

    $officeDoc = createEmployeePdfDocument(
        $company->id,
        $officeEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$officeEmployee->id}/passport/o.pdf",
        'Office.pdf',
    );
    $officeDoc->update(['expiry_date' => '2026-06-18']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSentCount(2);

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) {
        return $mail->hasTo('hr-manager@example.com')
            && ! $mail->hasCc('crewing@example.com')
            && count($mail->rows) === 2
            && collect($mail->rows)->pluck('employee_name')->contains('Office Person')
            && collect($mail->rows)->pluck('employee_name')->contains('Test Employee');
    });

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) {
        return $mail->hasTo('crewing@example.com')
            && count($mail->rows) === 1
            && $mail->rows[0]['employee_name'] === 'Test Employee'
            && collect($mail->rows)->pluck('employee_name')->doesntContain('Office Person');
    });
});

test('restricted CC with no visible employees receives nothing', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $officeEmployee, 'passportType' => $passportType] = makeDocumentFixtures();

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR-'.uniqid(),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $hr = User::factory()->create(['email' => 'hr-manager@example.com', 'status' => 'active']);
    grantCompanyPermissions($hr, $company, ['documents.view'], 'expiry-hr-role-2');

    $crewing = User::factory()->create(['email' => 'crewing@example.com', 'status' => 'active']);
    grantCompanyPermissions($crewing, $company, ['documents.view'], 'expiry-crewing-role-2');
    restrictTestRoleEmployeeVisibility($crewing, $company, [(int) $marineDepartment->id], 'expiry-crewing-role-2');

    createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$hr->id],
        'to_emails' => [],
        'cc_user_ids' => [$crewing->id],
        'cc_emails' => [],
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $officeEmployee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$officeEmployee->id}/passport/a.pdf",
        'Office.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSentCount(1);
    Mail::assertSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('hr-manager@example.com'));
    Mail::assertNotSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('crewing@example.com'));
});

test('internal recipient without documents.view is skipped at send time', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $member = User::factory()->create(['email' => 'member@example.com', 'status' => 'active']);
    attachActiveCompanyMember($member, $company->id);

    createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$member->id],
        'to_emails' => ['fallback@example.com'],
        'cc_emails' => [],
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('fallback@example.com'));
    Mail::assertNotSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('member@example.com'));
});

test('inactive document types do not generate new expiry notification emails', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    createDocumentExpiryNotificationRule($company->id);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    $passportType->update(['is_active' => false]);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertNothingSent();
    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(0);

    $passportType->update(['is_active' => true]);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSentCount(1);
});

test('legacy null-rule expiry alerts suppress duplicates after migration into routing rule', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    EmployeeDocumentExpiryAlert::query()->create([
        'company_id' => $company->id,
        'notification_rule_id' => null,
        'employee_document_id' => $doc->id,
        'expiry_date_at_alert_time' => '2026-06-20',
        'delivery_key' => DocumentExpiryAlertService::LegacyDeliveryKey,
        'alerted_at' => now(),
    ]);

    configureDocumentExpiryAlertTemplate([
        'to_preset' => 'legacy-to@example.com',
        'cc_preset' => null,
    ]);

    app(MigrateLegacyDocumentExpiryAlertRecipients::class)->handle();

    $rule = DocumentExpiryNotificationRule::query()
        ->where('company_id', $company->id)
        ->where('name', 'Employee Documents (migrated)')
        ->first();

    expect($rule)->not->toBeNull()
        ->and(EmployeeDocumentExpiryAlert::query()->where('notification_rule_id', $rule->id)->count())->toBe(1);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertNothingSent();

    $doc->update(['expiry_date' => '2026-06-25']);

    app(DocumentExpiryAlertService::class)->sendForCompany($company->id);

    Mail::assertSent(DocumentExpiryAlertMail::class, fn (DocumentExpiryAlertMail $mail) => $mail->hasTo('legacy-to@example.com'));
});
