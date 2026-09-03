<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
    Storage::fake('local');
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

test('legacy public esign and admin signature routes are removed', function () {
    expect(Route::has('public.esign.show'))->toBeFalse()
        ->and(Route::has('public.esign.submit'))->toBeFalse()
        ->and(Route::has('public.esign.download'))->toBeFalse()
        ->and(Route::has('organization.documents.bulk.signatures.approve'))->toBeFalse()
        ->and(Route::has('organization.documents.bulk.signatures.reject'))->toBeFalse()
        ->and(Route::has('organization.documents.bulk.signatures.upload'))->toBeFalse()
        ->and(Route::has('organization.documents.bulk.signatures.download'))->toBeFalse();

    $this->get('/esign/not-a-real-token')->assertNotFound();
});

test('historical submitted signature rows stay frozen when public esign is gone', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = createSalaryDeclarationDocument($company, $employee);
    $request = createLegacyBulkDocumentSignatureRequest(
        $company,
        $employee,
        $document,
        BulkDocumentSignatureRequestStatus::Submitted,
        ['signed_at' => now()],
    );

    $this->get('/esign/'.$request->token)->assertNotFound();

    expect($request->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Submitted)
        ->and($request->fresh()->signed_at)->not->toBeNull()
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(1);
});
