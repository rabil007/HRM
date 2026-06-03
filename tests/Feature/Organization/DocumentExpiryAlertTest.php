<?php

use App\Mail\DocumentExpiryAlertMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Services\DocumentExpiryAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

test('document expiry alert sends one email listing employees in name order', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();
    $employeeB = \App\Models\Employee::factory()->create([
        'company_id' => $company->id,
        'name' => 'Zara Khan',
    ]);
    $employeeC = \App\Models\Employee::factory()->create([
        'company_id' => $company->id,
        'name' => 'Ahmed Ali',
    ]);

    EmailTemplate::query()->where('slug', 'document_expiry_alert')->update([
        'to_preset' => 'hr@example.com',
        'cc_preset' => 'manager@example.com',
        'enabled' => true,
    ]);

    $docA = createEmployeePdfDocument(
        $company->id,
        $employeeC->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employeeC->id}/passport/c.pdf",
        'Passport C.pdf',
    );
    $docA->update(['expiry_date' => '2026-06-20']);

    $docB = createEmployeePdfDocument(
        $company->id,
        $employeeB->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employeeB->id}/passport/z.pdf",
        'Visa Z.pdf',
    );
    $docB->update(['expiry_date' => '2026-06-25']);

    $docOutsideWindow = createEmployeePdfDocument(
        $company->id,
        $employeeA->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employeeA->id}/passport/far.pdf",
        'Far.pdf',
    );
    $docOutsideWindow->update(['expiry_date' => '2026-08-01']);

    $sent = app(DocumentExpiryAlertService::class)->sendForCompany($company);

    expect($sent)->toBe(2);

    Mail::assertSent(DocumentExpiryAlertMail::class, function (DocumentExpiryAlertMail $mail) {
        return $mail->hasTo('hr@example.com')
            && $mail->hasCc('manager@example.com')
            && count($mail->employeeGroups) === 2
            && $mail->employeeGroups[0]['employee_name'] === 'Ahmed Ali'
            && $mail->employeeGroups[1]['employee_name'] === 'Zara Khan'
            && $mail->employeeGroups[0]['documents'][0]['remaining_days'] === 19;
    });

    expect(EmployeeDocumentExpiryAlert::query()->count())->toBe(2);

    expect(app(DocumentExpiryAlertService::class)->sendForCompany($company))->toBe(0);

    Mail::assertSentCount(1);
});

test('document expiry alert command processes companies', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    EmailTemplate::query()->where('slug', 'document_expiry_alert')->update([
        'to_preset' => 'alerts@example.com',
        'enabled' => true,
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-15']);

    $this->artisan('documents:send-expiry-alerts', ['--company' => $company->id])
        ->assertSuccessful();

    Mail::assertSent(DocumentExpiryAlertMail::class);
});

test('document expiry alert is skipped when template has no recipients', function () {
    Mail::fake();
    Carbon::setTestNow('2026-06-01');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    EmailTemplate::query()->where('slug', 'document_expiry_alert')->update([
        'to_preset' => null,
        'cc_preset' => null,
        'enabled' => true,
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-15']);

    expect(app(DocumentExpiryAlertService::class)->sendForCompany($company))->toBe(0);

    Mail::assertNothingSent();
});

test('document expiry alert template is seeded', function () {
    $template = EmailTemplate::query()->where('slug', 'document_expiry_alert')->first();

    expect($template)->not->toBeNull()
        ->and($template->category->value)->toBe('notification')
        ->and($template->is_default)->toBeTrue()
        ->and($template->subject)->toContain('30 days');
});
