<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
    Storage::fake('local');
    EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
});

function createSalaryDeclarationDocument(Company $company, Employee $employee): EmployeeDocument
{
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);

    return createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
        'declaration.pdf',
    );
}

function createAwaitingSignatureRequest(Company $company, Employee $employee, ?EmployeeDocument $document = null): BulkDocumentSignatureRequest
{
    $document ??= createSalaryDeclarationDocument($company, $employee);

    return createLegacyBulkDocumentSignatureRequest($company, $employee, $document);
}

test('bulk salary declaration email cannot create a new legacy signing request', function () {
    Mail::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'employee@example.com',
    ]);

    createSalaryDeclarationDocument($company, $employee);

    $this->post(route('organization.documents.bulk.email'), [
        'document_type_key' => 'salary_declaration',
        'employee_ids' => [$employee->id],
    ])->assertSessionHasErrors('document_type_key');

    expect(BulkDocumentSignatureRequest::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('guest can open valid signed signing page', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $request = createAwaitingSignatureRequest($company, $employee);

    $url = URL::temporarySignedRoute(
        'public.esign.show',
        now()->addDay(),
        ['token' => $request->token],
    );

    $this->get($url)
        ->assertOk()
        ->tap(fn ($response) => assertBrowserSecurityHeaders($response))
        ->assertInertia(fn ($page) => $page
            ->component('esign/index')
            ->where('employeeName', $employee->name)
            ->where('alreadySubmitted', false)
            ->where('unavailable', true)
            ->where('unavailableMessage', 'This signing link is no longer available. HR will send you a new document signing request.')
            ->where('documentLabel', 'Salary Declaration')
            ->has('downloadUrl')
            ->has('submitUrl'));
});

test('guest cannot open signing page with invalid signature', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $request = createAwaitingSignatureRequest($company, $employee);

    $this->get('/esign/'.$request->token)
        ->assertForbidden();
});

test('cancelled legacy signing request cannot be submitted', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Cancelled Signer',
    ]);
    $document = createSalaryDeclarationDocument($company, $employee);
    $request = createAwaitingSignatureRequest($company, $employee, $document);
    $originalPath = $document->file_path;

    $request->update(['status' => BulkDocumentSignatureRequestStatus::Cancelled]);

    $submitUrl = URL::temporarySignedRoute(
        'public.esign.submit',
        now()->addDay(),
        ['token' => $request->token],
    );

    $this->post($submitUrl, [
        'signed_name' => 'Cancelled Signer',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertSessionHasErrors('token');

    $request->refresh();
    $document->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::Cancelled)
        ->and($request->signed_pdf_path)->toBeNull()
        ->and($document->file_path)->toBe($originalPath);
});

test('guest can submit electronic signature without replacing employee document', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Signer Person',
    ]);

    $document = createSalaryDeclarationDocument($company, $employee);
    $request = createAwaitingSignatureRequest($company, $employee, $document);
    $originalPath = $document->file_path;

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $submitUrl = URL::temporarySignedRoute(
        'public.esign.submit',
        now()->addDay(),
        ['token' => $request->token],
    );

    $this->post($submitUrl, [
        'signed_name' => 'Signer Person',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertSessionHasErrors('token');

    $request->refresh();
    $document->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($request->signed_pdf_path)->toBeNull()
        ->and($request->signature_image_path)->toBeNull()
        ->and($request->signed_at)->toBeNull()
        ->and($document->file_path)->toBe($originalPath);
});

test('guest e-sign submit queues alignment regeneration for the signed request', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Aligned Signer',
    ]);

    $document = createSalaryDeclarationDocument($company, $employee);
    $request = createAwaitingSignatureRequest($company, $employee, $document);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $submitUrl = URL::temporarySignedRoute(
        'public.esign.submit',
        now()->addDay(),
        ['token' => $request->token],
    );

    $this->post($submitUrl, [
        'signed_name' => 'Aligned Signer',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertSessionHasErrors('token');

    $request->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and(BulkDocumentSignatureRepairRun::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('guest can submit uploaded signature image data url', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Upload Signer',
    ]);

    $document = createSalaryDeclarationDocument($company, $employee);
    $request = createAwaitingSignatureRequest($company, $employee, $document);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $submitUrl = URL::temporarySignedRoute(
        'public.esign.submit',
        now()->addDay(),
        ['token' => $request->token],
    );

    $this->post($submitUrl, [
        'signed_name' => 'Upload Signer',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertSessionHasErrors('token');

    $request->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($request->signature_image_path)->toBeNull()
        ->and($request->signed_pdf_path)->toBeNull();
});

test('hr can approve submitted signature and replace employee document', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    Storage::disk('local')->put($signedPath, minimalPdfBytes());

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'test-token-approve',
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_name' => 'Signer Person',
        'signed_pdf_path' => $signedPath,
        'signed_at' => now(),
        'expires_at' => now()->addDays(14),
    ]);

    $this->post(route('organization.documents.bulk.signatures.approve', $request))
        ->assertRedirect()
        ->assertSessionHas('success');

    $request->refresh();
    $document->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::Approved)
        ->and($document->file_path)->not->toBe("employee-documents/{$company->id}/{$employee->id}/declaration.pdf")
        ->and($document->current_version)->toBe(2);
});

test('hr can reject submitted signature without replacing employee document', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $originalPath = $document->file_path;
    $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    Storage::disk('local')->put($signedPath, minimalPdfBytes());

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'test-token-reject',
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_pdf_path' => $signedPath,
        'signed_at' => now(),
        'expires_at' => now()->addDays(14),
    ]);

    $this->post(route('organization.documents.bulk.signatures.reject', $request), [
        'reason' => 'Signature does not match records.',
    ])->assertRedirect()
        ->assertSessionHas('success');

    $request->refresh();
    $document->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::Rejected)
        ->and($request->rejection_reason)->toBe('Signature does not match records.')
        ->and($document->file_path)->toBe($originalPath);
});

test('hr can upload manual signed pdf into review queue', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $request = createAwaitingSignatureRequest($company, $employee, $document);

    $file = UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf');

    $this->post(route('organization.documents.bulk.signatures.upload', $request), [
        'file' => $file,
    ])->assertRedirect()
        ->assertSessionHas('success');

    $request->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::Submitted)
        ->and($request->signed_pdf_path)->not->toBeNull()
        ->and($request->signature_image_path)->toBeNull()
        ->and(Storage::disk('local')->exists((string) $request->signed_pdf_path))->toBeTrue();
});

test('signature review endpoints require review permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'test-token-permission',
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_pdf_path' => "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf",
        'expires_at' => now()->addDays(14),
    ]);

    $this->post(route('organization.documents.bulk.signatures.approve', $request))
        ->assertForbidden();
});

test('hr can view signed pdf inline from bulk signatures table', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    Storage::disk('local')->put($signedPath, minimalPdfBytes());

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'test-token-view-inline',
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_pdf_path' => $signedPath,
        'signed_at' => now(),
        'expires_at' => now()->addDays(14),
    ]);

    $this->get(route('organization.documents.bulk.signatures.download', [
        'signatureRequest' => $request,
        'inline' => 1,
    ]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="signed-salary-declaration.pdf"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('signed pdf download requires review permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    Storage::disk('local')->put($signedPath, minimalPdfBytes());

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'test-token-download-permission',
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_pdf_path' => $signedPath,
        'signed_at' => now(),
        'expires_at' => now()->addDays(14),
    ]);

    $this->get(route('organization.documents.bulk.signatures.download', $request))
        ->assertForbidden();
});
