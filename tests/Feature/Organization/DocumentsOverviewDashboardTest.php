<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentType;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\DocumentsOverviewQuery;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('overview renders actionable attention for an authorized company user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/overview-expired.pdf',
        'expiry_date' => now()->addDays(3)->toDateString(),
        'status' => 'valid',
    ]);

    $this->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/overview')
            ->where('summary.expiring_7', 1)
            ->where('attention.0.key', 'expiring_7')
            ->where('attention.0.destination', 'library')
            ->where('attention.0.query.expiry', 'expiring_7')
            ->has('compliance_types'));
});

test('overview missing and expiry counts stay company scoped', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, ['documents.view']);
    grantCompanyPermissions($user, $companyB, ['documents.view']);

    $employeeA = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $employeeB = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    makeDocumentRequirement($companyA->id, $documentType->id, requiredForAll: true);
    makeDocumentRequirement($companyB->id, $documentType->id, requiredForAll: true);

    EmployeeDocument::query()->create([
        'company_id' => $companyB->id,
        'employee_id' => $employeeB->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => 'employee-documents/b-expired.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    session(['current_company_id' => $companyA->id]);

    $payload = app(DocumentsOverviewQuery::class)->forCompany($companyA->id, $user);

    expect($payload['requirement_summary']['missing'])->toBe(1)
        ->and($payload['summary']['expired'])->toBe(0)
        ->and($employeeA->company_id)->toBe($companyA->id)
        ->and(collect($payload['attention'])->firstWhere('key', 'missing')['count'])->toBe(1)
        ->and(collect($payload['compliance_types'])->firstWhere('document_type_id', $documentType->id)['missing'])->toBe(1);
});

test('overview request metrics stay permission and company scoped', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'documents.view',
        'documents.requests.view',
        'documents.recipient-requests.view',
    ]);
    grantCompanyPermissions($user, $companyB, [
        'documents.view',
        'documents.requests.view',
        'documents.recipient-requests.view',
    ]);

    $ownFixtures = makeGeneratedDocumentWorkflowFixtures($companyA);
    $ownRequest = DocumentWorkflowRequest::query()->create([
        'company_id' => $companyA->id,
        'document_instance_id' => $ownFixtures['instance']->id,
        'document_instance_version_id' => $ownFixtures['version']->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $user->id,
        'requester_name_snapshot' => $user->name,
        'requested_at' => now(),
    ]);
    $ownStage = DocumentWorkflowStage::query()->create([
        'company_id' => $companyA->id,
        'document_workflow_request_id' => $ownRequest->id,
        'sequence' => 1,
        'action' => DocumentWorkflowAction::Approve,
        'completion_rule' => DocumentWorkflowCompletionRule::Any,
        'status' => DocumentWorkflowStageStatus::Active,
        'started_at' => now(),
    ]);
    DocumentWorkflowTask::query()->create([
        'company_id' => $companyA->id,
        'document_workflow_stage_id' => $ownStage->id,
        'assignee_user_id' => $user->id,
        'assignee_name_snapshot' => $user->name,
        'status' => DocumentWorkflowTaskStatus::Pending,
    ]);

    $foreignFixtures = makeGeneratedDocumentWorkflowFixtures($companyB);
    $foreignRequest = DocumentWorkflowRequest::query()->create([
        'company_id' => $companyB->id,
        'document_instance_id' => $foreignFixtures['instance']->id,
        'document_instance_version_id' => $foreignFixtures['version']->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $user->id,
        'requester_name_snapshot' => $user->name,
        'requested_at' => now(),
    ]);
    $foreignStage = DocumentWorkflowStage::query()->create([
        'company_id' => $companyB->id,
        'document_workflow_request_id' => $foreignRequest->id,
        'sequence' => 1,
        'action' => DocumentWorkflowAction::Approve,
        'completion_rule' => DocumentWorkflowCompletionRule::Any,
        'status' => DocumentWorkflowStageStatus::Active,
        'started_at' => now(),
    ]);
    DocumentWorkflowTask::query()->create([
        'company_id' => $companyB->id,
        'document_workflow_stage_id' => $foreignStage->id,
        'assignee_user_id' => $user->id,
        'assignee_name_snapshot' => $user->name,
        'status' => DocumentWorkflowTaskStatus::Pending,
    ]);

    DocumentRecipientRequest::query()->create([
        'company_id' => $companyA->id,
        'document_instance_id' => $ownFixtures['instance']->id,
        'source_document_instance_version_id' => $ownFixtures['version']->id,
        'action' => DocumentRecipientAction::Acknowledge,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $ownFixtures['employee']->id,
        'recipient_name_snapshot' => $ownFixtures['employee']->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', 'overview-a'),
        'expires_at' => now()->addDays(14),
        'requested_by' => $user->id,
        'requested_at' => now(),
        'source_checksum_sha256' => $ownFixtures['version']->checksum,
    ]);
    DocumentRecipientRequest::query()->create([
        'company_id' => $companyB->id,
        'document_instance_id' => $foreignFixtures['instance']->id,
        'source_document_instance_version_id' => $foreignFixtures['version']->id,
        'action' => DocumentRecipientAction::Acknowledge,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $foreignFixtures['employee']->id,
        'recipient_name_snapshot' => $foreignFixtures['employee']->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', 'overview-b'),
        'expires_at' => now()->addDays(14),
        'requested_by' => $user->id,
        'requested_at' => now(),
        'source_checksum_sha256' => $foreignFixtures['version']->checksum,
    ]);

    $payload = app(DocumentsOverviewQuery::class)->forCompany($companyA->id, $user);
    $attention = collect($payload['attention'])->keyBy('key');

    expect($attention['awaiting_action']['count'])->toBe(1)
        ->and($attention['awaiting_action']['query'])->toBe([
            'tab' => 'review',
            'status' => 'pending',
            'assigned_to_me' => '1',
        ])
        ->and($attention['awaiting_signature']['count'])->toBe(1)
        ->and($attention['awaiting_signature']['query']['tab'])->toBe('recipient');

    $viewer = User::factory()->create();
    grantCompanyPermissions($viewer, $companyA, ['documents.view']);

    $viewOnly = app(DocumentsOverviewQuery::class)->forCompany($companyA->id, $viewer);
    $viewOnlyKeys = collect($viewOnly['attention'])->pluck('key');

    expect($viewOnlyKeys)->not->toContain('awaiting_action')
        ->and($viewOnlyKeys)->not->toContain('awaiting_signature');
});

test('overview configure visibility stays on document type permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $this->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.configuration', false)
            ->where('compliance_types.0.document_type_id', $passportType->id)
            ->where('compliance_types.0.missing', 1));

    grantCompanyPermissions($user, $company, [
        'documents.view',
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.configuration', true));
});

test('library accepts a document type drill-down from overview', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'visaType' => $visaType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    makeDocumentRequirement($company->id, $visaType->id, requiredForAll: true);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/passport.pdf',
        'status' => 'valid',
    ]);

    $this->get(route('organization.documents.library', [
        'requirement_status' => 'missing',
        'document_type_id' => $visaType->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_type_id', (string) $visaType->id)
            ->where('requirementDocuments.data', function ($rows) use ($visaType) {
                return collect($rows)->every(fn ($row) => $row['document_type_id'] === $visaType->id);
            }));
});
