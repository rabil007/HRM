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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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

test('historical salary declaration requests remain readable and are not shown in Requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view', 'documents.recipient-requests.view']);
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
        ->assertRedirect(route('organization.documents.requests', ['tab' => 'recipient']));

    $this->get(route('organization.documents.requests', ['tab' => 'recipient']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/requests/index')
            ->where('tab', 'recipient')
            ->missing('signature_payload')
            ->has('recipient_requests', 0));

    expect($request->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Submitted)
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(1);
});

test('legacy public esign runtime is gone and does not mutate historical rows', function () {
    expect(Route::has('public.esign.show'))->toBeFalse();

    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);
    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";
    Storage::disk('local')->put($path, minimalPdfBytes());
    $document = createEmployeePdfDocument($company->id, $employee->id, $documentType->id, $path, 'declaration.pdf');
    $request = createLegacyBulkDocumentSignatureRequest($company, $employee, $document);
    $updatedAt = $request->updated_at?->toJSON();

    $this->get('/esign/'.$request->token)->assertNotFound();
    $this->post('/esign/'.$request->token, [
        'signed_name' => 'Retired Signer',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertNotFound();

    $request->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($request->updated_at?->toJSON())->toBe($updatedAt)
        ->and($request->signed_at)->toBeNull()
        ->and($request->signed_pdf_path)->toBeNull()
        ->and($request->signature_image_path)->toBeNull();
});
