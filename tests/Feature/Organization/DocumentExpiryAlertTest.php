<?php

use App\Jobs\SendDocumentExpiryAlertJob;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Models\User;
use App\Services\DocumentExpiryAlertService;
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
