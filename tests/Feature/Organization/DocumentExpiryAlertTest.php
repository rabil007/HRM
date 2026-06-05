<?php

use App\Jobs\SendDocumentExpiryAlertJob;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Services\DocumentExpiryAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array{to_preset?: string|null, cc_preset?: string|null, enabled?: bool}  $overrides
 */
function configureDocumentExpiryAlertTemplate(array $overrides = []): void
{
    $attributes = array_merge([
        'label' => 'Document expiry alert',
        'category' => 'notification',
        'to_preset' => 'hr@example.com',
        'cc_preset' => 'manager@example.com, hr@example.com',
        'subject' => 'Document Expiry Alert - Next 30 Days',
        'body_html' => 'Automated expiry summary email.',
        'is_default' => true,
        'enabled' => true,
        'sort_order' => 0,
    ], $overrides);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'document_expiry_alert'],
        $attributes,
    );
}

beforeEach(function () {
    config(['documents.expiry_alert_days' => 30]);
    configureDocumentExpiryAlertTemplate();
});

test('dispatch command queues job only when pending documents exist', function () {
    Queue::fake();
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

    $this->artisan('documents:dispatch-expiry-alerts', ['--company' => $company->id])
        ->assertSuccessful();

    Queue::assertPushed(SendDocumentExpiryAlertJob::class, fn (SendDocumentExpiryAlertJob $job) => $job->companyId === $company->id);

    Mail::assertSentCount(0);
});

test('dispatch command does nothing when expiry alert to preset is empty', function () {
    Queue::fake();
    configureDocumentExpiryAlertTemplate(['to_preset' => null]);

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

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) {
        return $mail->hasTo('hr@example.com')
            && $mail->hasCc('manager@example.com')
            && ! $mail->hasCc('hr@example.com')
            && $mail->envelope()->subject === 'Document Expiry Alert - Next 30 Days'
            && count($mail->rows) === 2
            && $mail->rows[0]['employee_name'] === 'Ahmed Ali'
            && $mail->rows[0]['employee_id'] === 'EMP-001'
            && $mail->rows[1]['employee_name'] === 'Zara Khan';
    });

    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(2);
});

test('second run does not resend alert for the same document and expiry date', function () {
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
