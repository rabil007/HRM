<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Jobs\RegenerateAlignedSignedBulkDocumentPdfsJob;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentSignatureStorage;
use App\Support\BulkDocuments\RegenerateSignedBulkDocumentPdf;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

function createSubmittedSignatureRequestForRepair(
    Company $company,
    Employee $employee,
    bool $withSignatureImage = true,
): BulkDocumentSignatureRequest {
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

    $signatureImagePath = null;

    if ($withSignatureImage) {
        $signatureImagePath = "bulk-document-signatures/{$company->id}/{$employee->id}/signature.png";
        BulkDocumentSignatureStorage::put($signatureImagePath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '');
    }

    $signedPdfPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    BulkDocumentSignatureStorage::put($signedPdfPath, '%PDF-1.4 original-signed');

    return BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat((string) $employee->id, 48),
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_name' => $employee->name,
        'signed_at' => now()->subDay(),
        'signature_image_path' => $signatureImagePath,
        'signed_pdf_path' => $signedPdfPath,
        'expires_at' => now()->addDays(14),
    ]);
}

test('regenerate alignment endpoint requires review permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $request = createSubmittedSignatureRequestForRepair($company, $employee);

    $this->post(route('organization.documents.bulk.signatures.regenerate-alignment'), [
        'signature_request_ids' => [$request->id],
        'document_type_key' => 'salary_declaration',
    ])->assertForbidden();
});

test('regenerate alignment endpoint creates run and dispatches job for eligible requests', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $eligibleEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Eligible Signer',
    ]);
    $ineligibleEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Manual Upload',
    ]);

    $eligible = createSubmittedSignatureRequestForRepair($company, $eligibleEmployee);
    $ineligible = createSubmittedSignatureRequestForRepair($company, $ineligibleEmployee, withSignatureImage: false);

    $this->post(route('organization.documents.bulk.signatures.regenerate-alignment'), [
        'signature_request_ids' => [$eligible->id, $ineligible->id],
        'document_type_key' => 'salary_declaration',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $run = BulkDocumentSignatureRepairRun::query()->first();

    expect($run)->not->toBeNull()
        ->and($run->total_count)->toBe(1)
        ->and($run->status)->toBe('queued')
        ->and($run->company_id)->toBe($company->id);

    Queue::assertPushed(RegenerateAlignedSignedBulkDocumentPdfsJob::class, function (
        RegenerateAlignedSignedBulkDocumentPdfsJob $job,
    ) use ($company, $user, $run, $eligible): bool {
        return $job->companyId === $company->id
            && $job->userId === $user->id
            && $job->repairRunId === $run->id
            && $job->requestIds === [$eligible->id];
    });
});

test('regenerate alignment job repairs signed pdf path via forced template render', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $request = createSubmittedSignatureRequestForRepair($company, $employee);
    $originalPath = $request->signed_pdf_path;

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public bool $called = false;

        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            $this->called = true;

            return '%PDF-1.4 repaired-aligned';
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $run = BulkDocumentSignatureRepairRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'status' => 'queued',
        'total_count' => 1,
        'initiated_by' => $user->id,
    ]);

    (new RegenerateAlignedSignedBulkDocumentPdfsJob(
        $company->id,
        $user->id,
        $run->id,
        [$request->id],
    ))->handle(app(RegenerateSignedBulkDocumentPdf::class));

    $request->refresh();
    $run->refresh();

    expect($renderer->called)->toBeTrue()
        ->and($request->signed_pdf_path)->not->toBe($originalPath)
        ->and(BulkDocumentSignatureStorage::exists((string) $request->signed_pdf_path))->toBeTrue()
        ->and(BulkDocumentSignatureStorage::disk()->get((string) $request->signed_pdf_path))->toBe('%PDF-1.4 repaired-aligned')
        ->and($run->status)->toBe('completed')
        ->and($run->repaired_count)->toBe(1)
        ->and($run->skipped_count)->toBe(0)
        ->and($run->failed_count)->toBe(0);
});

test('bulk documents page includes latest signature repair run', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, [
        'bulk_documents.view',
        'bulk_documents.signatures.review',
    ]);

    BulkDocumentSignatureRepairRun::query()->create([
        'company_id' => $company->id,
        'document_type_key' => 'salary_declaration',
        'status' => 'completed',
        'total_count' => 2,
        'repaired_count' => 2,
        'initiated_by' => $user->id,
        'finished_at' => now(),
    ]);

    $this->get(route('organization.documents.bulk', [
        'view' => 'signatures',
        'signature_filter' => 'submitted',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('latest_signature_repair_run.status', 'completed')
            ->where('latest_signature_repair_run.repaired_count', 2));
});

test('bulk signature selection returns regenerable request ids across pages', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $eligible = collect(range(1, 25))->map(function () use ($company) {
        $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

        return createSubmittedSignatureRequestForRepair($company, $employee);
    });

    $ineligibleEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    createSubmittedSignatureRequestForRepair($company, $ineligibleEmployee, withSignatureImage: false);

    $awaitingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $awaiting = createSubmittedSignatureRequestForRepair($company, $awaitingEmployee);
    $awaiting->update([
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'signed_at' => null,
        'signature_image_path' => null,
        'signed_pdf_path' => null,
    ]);

    $this->get(route('organization.documents.bulk.selection', [
        'view' => 'signatures',
        'signature_filter' => 'submitted',
    ]))
        ->assertOk()
        ->assertJsonPath('total', 25)
        ->assertJson(fn ($json) => $json
            ->where('total', 25)
            ->has('signature_request_ids', 25)
            ->etc());

    $ids = $this->get(route('organization.documents.bulk.selection', [
        'view' => 'signatures',
        'signature_filter' => 'submitted',
    ]))->json('signature_request_ids');

    expect($ids)
        ->toEqualCanonicalizing($eligible->pluck('id')->all())
        ->not->toContain($awaiting->id);
});

test('bulk signature selection respects signature filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $submittedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $submitted = createSubmittedSignatureRequestForRepair($company, $submittedEmployee);

    $awaitingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $awaiting = createSubmittedSignatureRequestForRepair($company, $awaitingEmployee);
    $awaiting->update([
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'signed_at' => null,
        'signature_image_path' => null,
        'signed_pdf_path' => null,
    ]);

    $this->get(route('organization.documents.bulk.selection', [
        'view' => 'signatures',
        'signature_filter' => 'awaiting_signature',
    ]))
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonPath('signature_request_ids', []);

    $this->get(route('organization.documents.bulk.selection', [
        'view' => 'signatures',
        'signature_filter' => 'submitted',
    ]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('signature_request_ids.0', $submitted->id);
});
