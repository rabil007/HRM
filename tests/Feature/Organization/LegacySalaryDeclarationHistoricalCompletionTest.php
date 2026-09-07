<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\BulkDocuments\CustomDocumentRosterQuery;
use App\Support\Employees\EmployeeDirectoryFilters;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/bulk-documents.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
    Storage::fake('local');
});

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     type: DocumentType,
 *     template: DocumentGenerationTemplate,
 *     version: DocumentGenerationTemplateVersion
 * }
 */
function makeSalaryDeclarationSuccessorWorkspace(): array
{
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, [
        'bulk_documents.view',
        'bulk_documents.generate',
        'documents.view',
        'documents.download',
    ]);
    $type = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Salary Declaration v3',
        'document_type_id' => $type->id,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    return compact('user', 'company', 'type', 'template', 'version');
}

function makeHistoricalSalaryDeclaration(
    Company $company,
    Employee $employee,
    DocumentType $type,
    BulkDocumentSignatureRequestStatus $status = BulkDocumentSignatureRequestStatus::Approved,
    array $overrides = [],
): BulkDocumentSignatureRequest {
    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";
    Storage::disk('local')->put($path, minimalPdfBytes());
    $document = createEmployeePdfDocument($company->id, $employee->id, $type->id, $path, 'declaration.pdf');

    $signedPath = "bulk-signatures/{$company->id}/{$employee->id}/signed.pdf";

    return createLegacyBulkDocumentSignatureRequest($company, $employee, $document, $status, array_merge([
        'signed_at' => $status === BulkDocumentSignatureRequestStatus::Approved ? now()->subDays(10) : null,
        'signed_pdf_path' => $status === BulkDocumentSignatureRequestStatus::Approved ? $signedPath : null,
        'reviewed_at' => $status === BulkDocumentSignatureRequestStatus::Approved ? now()->subDays(9) : null,
    ], $overrides));
}

test('legacy approved signed salary declaration is completed historical on the successor template', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $employee = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    makeHistoricalSalaryDeclaration($workspace['company'], $employee, $workspace['type']);

    $counts = CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
    );
    $page = CustomDocumentRosterQuery::paginate(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
        25,
        'all',
    );
    $row = collect($page->items())->firstWhere('id', $employee->id);

    expect($counts['all'])->toBe(1)
        ->and($counts['completed'])->toBe(1)
        ->and($counts['not_started'])->toBe(0)
        ->and($counts['not_generated'])->toBe(0)
        ->and($row['process']['status'])->toBe('completed')
        ->and($row['process']['label'])->toBe('Completed')
        ->and($row['process']['historical'])->toBeTrue()
        ->and($row['process']['secondary_label'])->toBe('Historical signed declaration')
        ->and($row['process']['waiting_for'])->toBeNull()
        ->and($row['process']['last_activity']['event'])->toBe('Signed')
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(1);
});

test('incomplete legacy salary declaration rows remain not started', function (
    BulkDocumentSignatureRequestStatus $status,
    array $overrides,
) {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $employee = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    makeHistoricalSalaryDeclaration($workspace['company'], $employee, $workspace['type'], $status, $overrides);

    $counts = CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
    );
    $page = CustomDocumentRosterQuery::paginate(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
        25,
        'all',
    );
    $row = collect($page->items())->firstWhere('id', $employee->id);

    expect($counts['completed'])->toBe(0)
        ->and($counts['not_started'])->toBe(1)
        ->and($row['process']['status'])->toBe('not_generated')
        ->and($row['process']['historical'] ?? false)->toBeFalse();
})->with([
    'approved without signed_at' => [
        BulkDocumentSignatureRequestStatus::Approved,
        ['signed_at' => null, 'signed_pdf_path' => 'legacy/signed.pdf'],
    ],
    'approved without signed_pdf_path' => [
        BulkDocumentSignatureRequestStatus::Approved,
        ['signed_at' => now(), 'signed_pdf_path' => null],
    ],
    'awaiting_signature' => [BulkDocumentSignatureRequestStatus::AwaitingSignature, []],
    'cancelled' => [BulkDocumentSignatureRequestStatus::Cancelled, []],
    'expired' => [BulkDocumentSignatureRequestStatus::Expired, []],
    'rejected' => [BulkDocumentSignatureRequestStatus::Rejected, []],
    'submitted' => [BulkDocumentSignatureRequestStatus::Submitted, ['signed_at' => now(), 'signed_pdf_path' => 'legacy/signed.pdf']],
]);

test('historical completed employees are excluded from generate missing and included in completed filter', function () {
    Queue::fake();

    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $historical = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active', 'name' => 'Historical Emp']);
    $missing = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active', 'name' => 'Missing Emp']);
    makeHistoricalSalaryDeclaration($workspace['company'], $historical, $workspace['type']);

    $filters = new EmployeeDirectoryFilters;
    $completed = CustomDocumentRosterQuery::paginate(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        $filters,
        25,
        'completed',
    );
    $notStarted = CustomDocumentRosterQuery::paginate(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        $filters,
        25,
        'not_started',
    );
    $selection = CustomDocumentRosterQuery::matchingSelection(
        $workspace['company']->id,
        $workspace['version'],
        $filters,
        'not_started',
    );

    expect($completed->total())->toBe(1)
        ->and($completed->items()[0]['id'])->toBe($historical->id)
        ->and($notStarted->total())->toBe(1)
        ->and($notStarted->items()[0]['id'])->toBe($missing->id)
        ->and($selection['employee_ids'])->toBe([$missing->id]);

    $this->actingAs($workspace['user'])
        ->withSession(['current_company_id' => $workspace['company']->id])
        ->getJson(route('organization.documents.bulk.selection', [
            'document_type_key' => 'custom_'.$workspace['template']->id,
            'process_filter' => 'not_started',
        ]))
        ->assertOk()
        ->assertJsonPath('employee_ids', [$missing->id]);

    $this->actingAs($workspace['user'])
        ->withSession(['current_company_id' => $workspace['company']->id])
        ->post(route('organization.documents.custom.generate'), [
            'document_generation_template_id' => $workspace['template']->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $run = DocumentGenerationRun::query()->where('company_id', $workspace['company']->id)->first();
    expect($run->total_targeted)->toBe(1)
        ->and($run->items()->pluck('employee_id')->all())->toBe([$missing->id])
        ->and(BulkDocumentSignatureRequest::query()->count())->toBe(1);
});

test('current signing flow wins over historical salary declaration completion', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $fixtures = makeGeneratedDocumentWorkflowFixtures($workspace['company']);
    $employee = $fixtures['employee'];
    $workspace['template']->update(['document_type_id' => $workspace['type']->id]);
    $template = $fixtures['template']->fresh();
    $template->update([
        'document_type_id' => $workspace['type']->id,
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $templateVersion = $fixtures['instance']->templateVersion;

    makeHistoricalSalaryDeclaration($workspace['company'], $employee, $workspace['type']);

    $signingFlow = DocumentSigningFlow::query()->create([
        'company_id' => $workspace['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'starting_document_instance_version_id' => $fixtures['version']->id,
        'preset_name_snapshot' => 'Standard signing',
        'routing_definition_snapshot' => ['schema_version' => 1],
        'started_by' => $workspace['user']->id,
        'started_at' => now(),
        'status' => 'active',
    ]);

    DocumentRecipientRequest::query()->create([
        'company_id' => $workspace['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_signing_flow_id' => $signingFlow->id,
        'source_document_instance_version_id' => $fixtures['version']->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $employee->id,
        'recipient_name_snapshot' => $employee->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(14),
        'requested_at' => now(),
        'source_checksum_sha256' => $fixtures['version']->checksum,
    ]);

    DocumentLifecycleAutomation::query()->create([
        'company_id' => $workspace['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'source_document_instance_version_id' => $fixtures['version']->id,
        'document_signing_flow_id' => $signingFlow->id,
        'status' => 'active',
        'stage' => 'signing',
        'policy_snapshot' => ['schema_version' => 1],
    ]);

    $counts = CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $template,
        $templateVersion,
        new EmployeeDirectoryFilters,
    );
    $page = CustomDocumentRosterQuery::paginate(
        $workspace['company']->id,
        $template,
        $templateVersion,
        new EmployeeDirectoryFilters,
        25,
        'all',
    );
    $row = collect($page->items())->firstWhere('id', $employee->id);

    expect($counts['completed'])->toBe(0)
        ->and($counts['in_progress'])->toBe(1)
        ->and($row['process']['status'])->toBe('awaiting_employee_signature')
        ->and($row['process']['historical'] ?? false)->toBeFalse();
});

test('historical and new completions count a shared employee once', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $historicalOnly = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    $both = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    makeHistoricalSalaryDeclaration($workspace['company'], $historicalOnly, $workspace['type']);
    makeHistoricalSalaryDeclaration($workspace['company'], $both, $workspace['type']);

    $libraryPath = "employee-documents/{$workspace['company']->id}/{$both->id}/new.pdf";
    Storage::disk('local')->put($libraryPath, minimalPdfBytes());
    $libraryDoc = EmployeeDocument::query()->create([
        'company_id' => $workspace['company']->id,
        'employee_id' => $both->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => $workspace['template']->name,
        'file_path' => $libraryPath,
        'original_filename' => 'new.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);
    DocumentInstance::query()->create([
        'company_id' => $workspace['company']->id,
        'employee_id' => $both->id,
        'employee_name_snapshot' => $both->name,
        'document_generation_template_id' => $workspace['template']->id,
        'document_generation_template_version_id' => $workspace['version']->id,
        'template_name_snapshot' => $workspace['template']->name,
        'template_version_number' => $workspace['version']->version,
        'title_snapshot' => $workspace['template']->name,
        'status' => 'generated',
        'employee_document_id' => $libraryDoc->id,
        'generated_at' => now(),
    ]);

    $counts = CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
    );

    expect($counts['all'])->toBe(2)
        ->and($counts['completed'])->toBe(2)
        ->and($counts['not_started'])->toBe(0);
});

test('cross-company historical salary declarations never satisfy completion', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $other = setupBulkDocumentsCompany(User::factory()->create(), ['bulk_documents.view']);
    $employee = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    $foreignEmployee = Employee::factory()->forCompany($other)->create(['status' => 'active']);
    makeHistoricalSalaryDeclaration($other, $foreignEmployee, $workspace['type']);

    $counts = CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
    );

    expect($counts['completed'])->toBe(0)
        ->and($counts['not_started'])->toBe(1)
        ->and($counts['all'])->toBe(1)
        ->and($employee->company_id)->toBe($workspace['company']->id);
});

test('historical completion is not applied to templates that are not the salary declaration successor', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $employee = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    makeHistoricalSalaryDeclaration($workspace['company'], $employee, $workspace['type']);

    $otherType = DocumentType::query()->create(['title' => 'Offer Letter', 'is_active' => true]);
    $workspace['template']->update(['document_type_id' => $otherType->id]);

    $counts = CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $workspace['template']->fresh(),
        $workspace['version'],
        new EmployeeDirectoryFilters,
    );

    expect($counts['completed'])->toBe(0)
        ->and($counts['not_started'])->toBe(1);
});

test('historical journey is read-only and uses the authorized document preview', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $employee = Employee::factory()->forCompany($workspace['company'])->create(['status' => 'active']);
    $legacy = makeHistoricalSalaryDeclaration($workspace['company'], $employee, $workspace['type']);

    $response = $this->actingAs($workspace['user'])
        ->withSession(['current_company_id' => $workspace['company']->id])
        ->getJson(route('organization.documents.journey', [
            'employee_id' => $employee->id,
            'version_id' => $workspace['version']->id,
        ]))
        ->assertOk();

    $json = $response->json();

    expect($json['process']['status'])->toBe('completed')
        ->and($json['process']['historical'])->toBeTrue()
        ->and($json['process']['authorized_action_url'])->toBeNull()
        ->and($json['permissions']['can_resend_action_email'])->toBeFalse()
        ->and($json['permissions']['can_retry_lifecycle'])->toBeFalse()
        ->and($json['action_email_banner'])->toBeNull()
        ->and($json['document']['view_url'])->toContain('/organization/documents/files/'.$legacy->employee_document_id.'/preview')
        ->and(collect($json['events'])->pluck('title')->all())->toContain('Historical declaration generated')
        ->and(collect($json['events'])->pluck('title')->all())->toContain('Employee signed')
        ->and(collect($json['events'])->pluck('title')->all())->toContain('Approved');
});

test('historical bulk cohort does not query once per employee', function () {
    $workspace = makeSalaryDeclarationSuccessorWorkspace();
    $employees = Employee::factory()->forCompany($workspace['company'])->count(20)->create(['status' => 'active']);

    foreach ($employees as $employee) {
        makeHistoricalSalaryDeclaration($workspace['company'], $employee, $workspace['type']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    CustomDocumentRosterQuery::counts(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
    );
    CustomDocumentRosterQuery::paginate(
        $workspace['company']->id,
        $workspace['template'],
        $workspace['version'],
        new EmployeeDirectoryFilters,
        25,
        'all',
    );

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(40)
        ->and(CustomDocumentRosterQuery::counts(
            $workspace['company']->id,
            $workspace['template'],
            $workspace['version'],
            new EmployeeDirectoryFilters,
        )['completed'])->toBe(20);
});
