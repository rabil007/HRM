<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Mail\BulkDocumentMail;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentSignatureLinkService;
use App\Support\BulkDocuments\CreateBulkDocumentSignatureRequest;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
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

    return app(CreateBulkDocumentSignatureRequest::class)->handle(
        $company->id,
        $employee->id,
        $document,
        'salary_declaration',
    );
}

function minimalSignatureDataUrl(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );

    return 'data:image/png;base64,'.base64_encode($png ?: '');
}

test('bulk email send creates signature request and substitutes signing url', function () {
    Mail::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.email']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'employee@example.com',
    ]);

    createSalaryDeclarationDocument($company, $employee);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId, ?array $signature = null): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $this->post(route('organization.documents.bulk.email'), [
        'document_type_key' => 'salary_declaration',
        'employee_ids' => [$employee->id],
    ])->assertRedirect()
        ->assertSessionHas('success');

    expect(BulkDocumentSignatureRequest::query()->count())->toBe(1);

    $request = BulkDocumentSignatureRequest::query()->first();
    $signUrl = app(BulkDocumentSignatureLinkService::class)->signUrl($request);

    Mail::assertQueued(BulkDocumentMail::class, function ($mail) use ($signUrl) {
        return str_contains($mail->bodyMessage, $signUrl)
            && str_contains($mail->bodyMessage, 'Sign declaration')
            && str_contains($mail->bodyMessage, '/esign/');
    });
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
        ->assertInertia(fn ($page) => $page
            ->component('esign/index')
            ->where('employeeName', $employee->name)
            ->where('alreadySubmitted', false)
            ->has('placement')
            ->where('placement.page', 1)
            ->has('placement.overlay')
            ->has('placement.stamps'));
});

test('guest cannot open signing page with invalid signature', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $request = createAwaitingSignatureRequest($company, $employee);

    $this->get('/esign/'.$request->token)
        ->assertForbidden();
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

    $submitUrl = URL::temporarySignedRoute(
        'public.esign.submit',
        now()->addDay(),
        ['token' => $request->token],
    );

    $this->post($submitUrl, [
        'signed_name' => 'Signer Person',
        'signature_data' => minimalSignatureDataUrl(),
        'consent' => '1',
    ])->assertRedirect();

    $request->refresh();
    $document->refresh();

    expect($request->status)->toBe(BulkDocumentSignatureRequestStatus::Submitted)
        ->and($request->signed_pdf_path)->not->toBeNull()
        ->and($document->file_path)->toBe($originalPath);
});

test('hr can approve submitted signature and replace employee document', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    Storage::disk('public')->put($signedPath, minimalPdfBytes());

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
    Storage::disk('public')->put($signedPath, minimalPdfBytes());

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
        ->and($request->signature_image_path)->toBeNull();
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
