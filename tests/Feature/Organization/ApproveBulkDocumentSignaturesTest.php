<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

test('hr can bulk approve submitted signature requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    $firstEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $secondEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $awaitingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $requests = collect([$firstEmployee, $secondEmployee])->map(function (Employee $employee) use ($company, $documentType) {
        $document = createEmployeePdfDocument(
            $company->id,
            $employee->id,
            $documentType->id,
            "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
            'declaration.pdf',
        );

        $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
        Storage::disk('local')->put($signedPath, minimalPdfBytes());

        return BulkDocumentSignatureRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'employee_document_id' => $document->id,
            'document_type_key' => 'salary_declaration',
            'token' => 'bulk-approve-'.$employee->id,
            'status' => BulkDocumentSignatureRequestStatus::Submitted,
            'signed_name' => $employee->name,
            'signed_pdf_path' => $signedPath,
            'signed_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);
    });

    $awaitingDocument = createEmployeePdfDocument(
        $company->id,
        $awaitingEmployee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$awaitingEmployee->id}/declaration.pdf",
        'declaration.pdf',
    );

    $awaitingRequest = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $awaitingEmployee->id,
        'employee_document_id' => $awaitingDocument->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'bulk-approve-awaiting',
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'expires_at' => now()->addDays(14),
    ]);

    $this->post(route('organization.documents.bulk.signatures.approve-many'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [
            $requests[0]->id,
            $requests[1]->id,
            $awaitingRequest->id,
        ],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($requests[0]->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Approved)
        ->and($requests[1]->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Approved)
        ->and($awaitingRequest->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($firstEmployee->documents()->first()->current_version)->toBe(2)
        ->and($secondEmployee->documents()->first()->current_version)->toBe(2);
});

test('bulk approve requires review permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->post(route('organization.documents.bulk.signatures.approve-many'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [1],
    ])->assertForbidden();
});

test('bulk approve reports when nothing eligible was selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
        'declaration.pdf',
    );

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'bulk-approve-none',
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'expires_at' => now()->addDays(14),
    ]);

    $this->post(route('organization.documents.bulk.signatures.approve-many'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [$request->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('info');

    expect($request->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature);
});
