<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\DocumentType;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Support\BulkDocuments\BulkDocumentEmailComposer;
use App\Support\BulkDocuments\LegacySalaryDeclarationSigning;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
    Storage::fake('local');
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
});

test('email composer does not create a salary declaration signing request or send mail', function () {
    Mail::fake();

    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'employee@example.com',
    ]);
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);
    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $documentType->id, $path, 'declaration.pdf');

    $template = EmailTemplate::query()->where('slug', 'bulk_salary_declaration')->firstOrFail();
    $batch = BulkDocumentEmailBatch::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'email_template_id' => $template->id,
        'subject' => $template->subject,
        'total_selected' => 1,
        'status' => 'running',
        'triggered_by' => $user->id,
    ]);

    $result = app(BulkDocumentEmailComposer::class)->sendForEmployee(
        $company->id,
        $batch->id,
        'salary_declaration',
        $employee,
        $company,
        $template,
        'Salary Declaration',
        $documentType->id,
    );

    expect($result['failed'])->toBe(1)
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(0)
        ->and(BulkDocumentEmailSend::query()->value('error'))->toBe(LegacySalaryDeclarationSigning::SIGNING_RETIREMENT_MESSAGE);

    Mail::assertNothingQueued();
    expect($document->fresh()->file_path)->toBe($path);
});

test('historical salary declaration requests remain readable', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'bulk_documents.signatures.review']);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);
    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";
    Storage::disk('local')->put($path, minimalPdfBytes());
    $document = createEmployeePdfDocument($company->id, $employee->id, $documentType->id, $path, 'declaration.pdf');

    $request = createLegacyBulkDocumentSignatureRequest(
        $company,
        $employee,
        $document,
        BulkDocumentSignatureRequestStatus::Submitted,
        ['signed_at' => now()],
    );

    $this->get(route('organization.documents.requests', [
        'tab' => 'signatures',
        'document_type_key' => 'salary_declaration',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'signatures')
            ->where('signature_payload.signature_requests.0.id', $request->id)
            ->where('signature_payload.signature_requests.0.status', 'submitted'));
});

test('cancelled and submitted public esign links cannot accept a new signature', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Link Signer',
    ]);
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);
    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";
    Storage::disk('local')->put($path, minimalPdfBytes());
    $document = createEmployeePdfDocument($company->id, $employee->id, $documentType->id, $path, 'declaration.pdf');

    $cancelled = createLegacyBulkDocumentSignatureRequest(
        $company,
        $employee,
        $document,
        BulkDocumentSignatureRequestStatus::Cancelled,
    );

    $showUrl = URL::temporarySignedRoute(
        'public.esign.show',
        now()->addDay(),
        ['token' => $cancelled->token],
    );

    $this->get($showUrl)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unavailable', true)
            ->where('alreadySubmitted', false)
            ->where('status', 'cancelled'));

    $submitted = createLegacyBulkDocumentSignatureRequest(
        $company,
        Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Done Signer']),
        $document,
        BulkDocumentSignatureRequestStatus::Submitted,
        ['signed_at' => now()],
    );

    $submitUrl = URL::temporarySignedRoute(
        'public.esign.submit',
        now()->addDay(),
        ['token' => $submitted->token],
    );

    $this->post($submitUrl, [
        'signed_name' => 'Done Signer',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertSessionHasErrors('token');

    expect($submitted->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Submitted);
});
