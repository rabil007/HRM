<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\BulkDocuments\CreateBulkDocumentSignatureRequest;
use App\Support\BulkDocuments\LegacySalaryDeclarationSigning;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Exception\InvalidOptionException;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
    Storage::fake('local');
});

function makeCutoverDeclarationDocument(Company $company, Employee $employee): EmployeeDocument
{
    $documentType = DocumentType::query()->firstOrCreate(['title' => 'Salary Declaration'], ['is_active' => true]);
    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";

    return createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        $path,
        'declaration.pdf',
    );
}

function snapshotLegacyRequest(BulkDocumentSignatureRequest $request): array
{
    $request->refresh();

    return [
        'status' => $request->status,
        'token' => $request->token,
        'employee_document_id' => $request->employee_document_id,
        'document_type_key' => $request->document_type_key,
        'signed_pdf_path' => $request->signed_pdf_path,
        'signed_at' => $request->signed_at?->toJSON(),
        'expires_at' => $request->expires_at?->toJSON(),
        'created_at' => $request->created_at?->toJSON(),
        'updated_at' => $request->updated_at?->toJSON(),
    ];
}

test('report is read-only and leaves awaiting_signature unchanged', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'employee_no' => 'EMP-0004',
        'name' => 'Ahmed Ali',
    ]);
    $document = makeCutoverDeclarationDocument($company, $employee);
    $request = createLegacyBulkDocumentSignatureRequest($company, $employee, $document);
    $token = $request->token;
    $snapshot = snapshotLegacyRequest($request);
    $documentUpdatedAt = $document->fresh()->updated_at?->toJSON();
    $activityCount = Activity::query()->count();

    $this->artisan('documents:legacy-signatures-cutover', [
        '--company' => $company->id,
    ])
        ->expectsOutputToContain('Read-only legacy Salary Declaration signature report. No rows or files will be changed.')
        ->doesntExpectOutputToContain('Dry-run')
        ->doesntExpectOutputToContain('--execute')
        ->expectsOutputToContain('Awaiting signature: 1')
        ->expectsOutputToContain('Submitted: 0')
        ->expectsOutputToContain('EMP-0004')
        ->doesntExpectOutputToContain($token)
        ->assertSuccessful();

    $request->refresh();
    $document->refresh();

    expect(snapshotLegacyRequest($request))->toBe($snapshot)
        ->and($request->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($document->file_path)->toBe("employee-documents/{$company->id}/{$employee->id}/declaration.pdf")
        ->and($document->updated_at?->toJSON())->toBe($documentUpdatedAt)
        ->and(Storage::disk('public')->exists($document->file_path))->toBeTrue()
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe($activityCount);
});

test('report and export leave every legacy status and file unchanged', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $otherCompany = setupBulkDocumentsCompany(User::factory()->create(), ['bulk_documents.view']);

    $awaitingEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'employee_no' => 'EMP-0011',
        'name' => 'Sara Khan',
    ]);
    $submittedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $approvedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $rejectedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $expiredEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $cancelledEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);
    $certificateEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'employee_no' => 'EMP-CERT',
        'name' => 'Certificate Holder',
    ]);

    $awaitingDocument = makeCutoverDeclarationDocument($company, $awaitingEmployee);
    $submittedDocument = makeCutoverDeclarationDocument($company, $submittedEmployee);
    $signedPath = "bulk-document-signatures/{$company->id}/{$submittedEmployee->id}/signed.pdf";
    Storage::disk('local')->put($signedPath, 'signed-bytes');

    $awaiting = createLegacyBulkDocumentSignatureRequest($company, $awaitingEmployee, $awaitingDocument);
    $submitted = createLegacyBulkDocumentSignatureRequest(
        $company,
        $submittedEmployee,
        $submittedDocument,
        BulkDocumentSignatureRequestStatus::Submitted,
        [
            'signed_pdf_path' => $signedPath,
            'signed_at' => now(),
        ],
    );
    $approved = createLegacyBulkDocumentSignatureRequest(
        $company,
        $approvedEmployee,
        makeCutoverDeclarationDocument($company, $approvedEmployee),
        BulkDocumentSignatureRequestStatus::Approved,
    );
    $rejected = createLegacyBulkDocumentSignatureRequest(
        $company,
        $rejectedEmployee,
        makeCutoverDeclarationDocument($company, $rejectedEmployee),
        BulkDocumentSignatureRequestStatus::Rejected,
    );
    $expired = createLegacyBulkDocumentSignatureRequest(
        $company,
        $expiredEmployee,
        makeCutoverDeclarationDocument($company, $expiredEmployee),
        BulkDocumentSignatureRequestStatus::Expired,
    );
    $alreadyCancelled = createLegacyBulkDocumentSignatureRequest(
        $company,
        $cancelledEmployee,
        makeCutoverDeclarationDocument($company, $cancelledEmployee),
        BulkDocumentSignatureRequestStatus::Cancelled,
    );
    $otherAwaiting = createLegacyBulkDocumentSignatureRequest(
        $otherCompany,
        $otherEmployee,
        makeCutoverDeclarationDocument($otherCompany, $otherEmployee),
    );
    $certificate = createLegacyBulkDocumentSignatureRequest(
        $company,
        $certificateEmployee,
        makeCutoverDeclarationDocument($company, $certificateEmployee),
        BulkDocumentSignatureRequestStatus::AwaitingSignature,
        ['document_type_key' => 'salary_certificate'],
    );

    $snapshots = [
        $awaiting->id => snapshotLegacyRequest($awaiting),
        $submitted->id => snapshotLegacyRequest($submitted),
        $approved->id => snapshotLegacyRequest($approved),
        $rejected->id => snapshotLegacyRequest($rejected),
        $expired->id => snapshotLegacyRequest($expired),
        $alreadyCancelled->id => snapshotLegacyRequest($alreadyCancelled),
        $otherAwaiting->id => snapshotLegacyRequest($otherAwaiting),
        $certificate->id => snapshotLegacyRequest($certificate),
    ];

    $awaitingPath = $awaitingDocument->file_path;
    $submittedPath = $submittedDocument->file_path;
    $awaitingDocumentUpdatedAt = $awaitingDocument->fresh()->updated_at?->toJSON();
    $activityCount = Activity::query()->count();
    $export = sys_get_temp_dir().'/legacy-cutover-'.$company->id.'.csv';

    $this->artisan('documents:legacy-signatures-cutover', [
        '--company' => $company->id,
        '--export' => $export,
    ])
        ->expectsOutputToContain('Read-only legacy Salary Declaration signature report. No rows or files will be changed.')
        ->expectsOutputToContain('Total legacy requests: 6')
        ->expectsOutputToContain('Awaiting signature: 1')
        ->expectsOutputToContain('Submitted: 1')
        ->expectsOutputToContain('Approved: 1')
        ->expectsOutputToContain('Rejected: 1')
        ->expectsOutputToContain('Expired: 1')
        ->expectsOutputToContain('Cancelled: 1')
        ->expectsOutputToContain('EMP-0011')
        ->doesntExpectOutputToContain('EMP-CERT')
        ->doesntExpectOutputToContain($awaiting->token)
        ->doesntExpectOutputToContain($otherAwaiting->token)
        ->assertSuccessful();

    foreach ([$awaiting, $submitted, $approved, $rejected, $expired, $alreadyCancelled, $otherAwaiting, $certificate] as $row) {
        expect(snapshotLegacyRequest($row))->toBe($snapshots[$row->id]);
    }

    expect($awaiting->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($submitted->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Submitted)
        ->and($approved->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Approved)
        ->and($rejected->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Rejected)
        ->and($expired->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Expired)
        ->and($alreadyCancelled->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::Cancelled)
        ->and($otherAwaiting->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($certificate->fresh()->status)->toBe(BulkDocumentSignatureRequestStatus::AwaitingSignature)
        ->and($awaitingDocument->fresh()->file_path)->toBe($awaitingPath)
        ->and($awaitingDocument->fresh()->updated_at?->toJSON())->toBe($awaitingDocumentUpdatedAt)
        ->and($submittedDocument->fresh()->file_path)->toBe($submittedPath)
        ->and(Storage::disk('local')->get($signedPath))->toBe('signed-bytes')
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(8)
        ->and(EmployeeDocument::query()->count())->toBe(8)
        ->and(Activity::query()->count())->toBe($activityCount);

    expect(is_file($export))->toBeTrue();

    $csv = file_get_contents($export);

    expect($csv)->toContain('employee_id,employee_no,employee_name,legacy_request_id,employee_document_id')
        ->and($csv)->toContain((string) $awaitingEmployee->id)
        ->and($csv)->toContain('EMP-0011')
        ->and($csv)->toContain((string) $awaiting->id)
        ->and($csv)->toContain((string) $awaitingDocument->id)
        ->and($csv)->not->toContain((string) $submittedEmployee->id)
        ->and($csv)->not->toContain((string) $otherEmployee->id)
        ->and($csv)->not->toContain('EMP-CERT')
        ->and($csv)->not->toContain($awaiting->token)
        ->and($csv)->not->toContain($submitted->token);

    @unlink($export);
});

test('export requires a company id', function () {
    $this->artisan('documents:legacy-signatures-cutover', [
        '--export' => sys_get_temp_dir().'/legacy-cutover-missing-company.csv',
    ])
        ->expectsOutputToContain('The --company option is required when using --export.')
        ->assertFailed();
});

test('execute option does not exist', function () {
    expect(fn () => $this->artisan('documents:legacy-signatures-cutover', [
        '--company' => 1,
        '--execute' => true,
    ]))->toThrow(InvalidOptionException::class, 'The "--execute" option does not exist.');
});

test('creating a new salary declaration signature request is rejected', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $document = makeCutoverDeclarationDocument($company, $employee);

    expect(fn () => app(CreateBulkDocumentSignatureRequest::class)->handle(
        $company->id,
        $employee->id,
        $document,
        'salary_declaration',
    ))->toThrow(ValidationException::class);

    try {
        app(CreateBulkDocumentSignatureRequest::class)->handle(
            $company->id,
            $employee->id,
            $document,
            'salary_declaration',
        );
    } catch (ValidationException $exception) {
        expect($exception->errors()['document_type_key'][0])
            ->toBe(LegacySalaryDeclarationSigning::SIGNING_RETIREMENT_MESSAGE);
    }

    expect(BulkDocumentSignatureRequest::query()->count())->toBe(0);
});
