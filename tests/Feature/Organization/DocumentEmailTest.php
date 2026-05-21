<?php

use App\Mail\DocumentsSharedMail;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

test('guests cannot email employee documents', function () {
    $this->postJson(route('organization.documents.employee.files.email', 1), [
        'document_ids' => [1],
        'recipient' => 'recipient@example.com',
        'subject' => 'Documents',
    ])->assertUnauthorized();
});

test('users can email selected employee documents as attachments', function () {
    Mail::fake();
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pathA = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf";

    $docA = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathA, 'Passport.pdf');
    $docB = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathB, 'Visa.pdf');

    $docA->update(['size_bytes' => Storage::disk('public')->size($pathA)]);
    $docB->update(['size_bytes' => Storage::disk('public')->size($pathB)]);

    $response = $this->postJson(route('organization.documents.employee.files.email', $employee), [
        'document_ids' => [$docA->id, $docB->id],
        'recipient' => 'recipient@example.com',
        'cc' => 'cc@example.com',
        'subject' => 'Employee documents',
        'message' => 'Please review the attached documents.',
    ]);

    $response->assertOk()->assertJson(['message' => 'Email sent successfully.']);

    Mail::assertSent(DocumentsSharedMail::class, function (DocumentsSharedMail $mail) {
        return $mail->hasTo('recipient@example.com')
            && $mail->hasCc('cc@example.com')
            && $mail->subjectLine === 'Employee documents'
            && count($mail->attachments()) === 2;
    });

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('emailed')
        ->and($activity->description)->toBe('Employee documents sent via email')
        ->and($activity->properties->get('recipient'))->toBe('recipient@example.com')
        ->and($activity->properties->get('document_count'))->toBe(2);
});

test('duplicate cc matching recipient is not sent twice', function () {
    Mail::fake();
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');
    $doc->update(['size_bytes' => Storage::disk('public')->size($path)]);

    $this->postJson(route('organization.documents.employee.files.email', $employee), [
        'document_ids' => [$doc->id],
        'recipient' => 'recipient@example.com',
        'cc' => 'recipient@example.com, other@example.com',
        'subject' => 'Employee documents',
    ])->assertOk();

    Mail::assertSent(DocumentsSharedMail::class, function (DocumentsSharedMail $mail) {
        return $mail->hasTo('recipient@example.com')
            && $mail->hasCc('other@example.com')
            && ! $mail->hasCc('recipient@example.com');
    });
});

test('email requires at least one document id', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->postJson(route('organization.documents.employee.files.email', $employee), [
        'document_ids' => [],
        'recipient' => 'recipient@example.com',
        'subject' => 'Documents',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids']);
});

test('email rejects oversized total attachments', function () {
    Mail::fake();
    Storage::fake('public');
    config(['services.documents.email_max_attachment_bytes' => 1024]);

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pathA = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf";

    $docA = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathA, 'Large A.pdf');
    $docB = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathB, 'Large B.pdf');

    $docA->update(['size_bytes' => 700]);
    $docB->update(['size_bytes' => 700]);

    $this->postJson(route('organization.documents.employee.files.email', $employee), [
        'document_ids' => [$docA->id, $docB->id],
        'recipient' => 'recipient@example.com',
        'subject' => 'Documents',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids']);

    Mail::assertNothingSent();
});

test('email rejects invalid cc addresses', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');
    $doc->update(['size_bytes' => Storage::disk('public')->size($path)]);

    $this->postJson(route('organization.documents.employee.files.email', $employee), [
        'document_ids' => [$doc->id],
        'recipient' => 'recipient@example.com',
        'cc' => 'not-an-email',
        'subject' => 'Documents',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['cc']);
});

test('users cannot email documents for employees in another company', function () {
    Mail::fake();
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pathA = "employee-documents/{$otherEmployee->company_id}/{$otherEmployee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$otherEmployee->company_id}/{$otherEmployee->id}/passport/b.pdf";

    $docA = createEmployeePdfDocument($otherEmployee->company_id, $otherEmployee->id, $passportType->id, $pathA, 'A.pdf');
    $docB = createEmployeePdfDocument($otherEmployee->company_id, $otherEmployee->id, $passportType->id, $pathB, 'B.pdf');

    $this->postJson(route('organization.documents.employee.files.email', $otherEmployee), [
        'document_ids' => [$docA->id, $docB->id],
        'recipient' => 'recipient@example.com',
        'subject' => 'Documents',
    ])->assertNotFound();

    Mail::assertNothingSent();
});

test('users cannot email documents belonging to another employee', function () {
    Mail::fake();
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $otherEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employee->branch_id,
        'employee_no' => 'DOC999',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $ownPath = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $otherPath = "employee-documents/{$company->id}/{$otherEmployee->id}/passport/b.pdf";

    $ownDoc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $ownPath, 'Own.pdf');
    $otherDoc = createEmployeePdfDocument($company->id, $otherEmployee->id, $passportType->id, $otherPath, 'Other.pdf');

    $this->postJson(route('organization.documents.employee.files.email', $employee), [
        'document_ids' => [$ownDoc->id, $otherDoc->id],
        'recipient' => 'recipient@example.com',
        'subject' => 'Documents',
    ])->assertNotFound();

    Mail::assertNothingSent();
});

test('bulk zip download still works after email endpoint is available', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Visa Front.pdf');

    $this->postJson(route('organization.documents.files.bulk-download'), [
        'document_ids' => [$doc->id],
    ])->assertOk()->assertDownload('documents_export.zip');
});
