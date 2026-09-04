<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\Employee;
use App\Models\User;
use App\Support\BulkDocuments\CustomDocumentRosterQuery;
use App\Support\Employees\EmployeeDirectoryFilters;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
});

test('custom document roster query returns operational process payloads and lifecycle counts', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $template = $fixtures['template'];
    $templateVersion = $fixtures['instance']->templateVersion;
    $user = User::factory()->create();

    // Employee 1: has generated document instance in signing stage
    $employee1 = $fixtures['employee'];
    $instance1 = $fixtures['instance'];
    $version1 = $fixtures['version'];

    $signingFlow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance1->id,
        'starting_document_instance_version_id' => $version1->id,
        'preset_name_snapshot' => 'Standard signing',
        'routing_definition_snapshot' => ['schema_version' => 1],
        'started_by' => $user->id,
        'started_at' => now(),
        'status' => 'active',
    ]);

    DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance1->id,
        'document_signing_flow_id' => $signingFlow->id,
        'source_document_instance_version_id' => $version1->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $employee1->id,
        'recipient_name_snapshot' => $employee1->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(14),
        'requested_at' => now(),
        'source_checksum_sha256' => $version1->checksum,
    ]);

    DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance1->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'source_document_instance_version_id' => $version1->id,
        'document_signing_flow_id' => $signingFlow->id,
        'status' => 'active',
        'stage' => 'signing',
        'policy_snapshot' => ['schema_version' => 1],
    ]);

    // Unstarted employee from fixtures
    $unstartedEmployee = Employee::query()
        ->where('company_id', $company->id)
        ->where('id', '!=', $employee1->id)
        ->firstOrFail();

    $counts = CustomDocumentRosterQuery::counts(
        companyId: $company->id,
        template: $template,
        version: $templateVersion,
        filters: new EmployeeDirectoryFilters,
    );

    $paginator = CustomDocumentRosterQuery::paginate(
        companyId: $company->id,
        template: $template,
        version: $templateVersion,
        filters: new EmployeeDirectoryFilters,
        perPage: 25,
        filter: 'all',
    );

    // Lifecycle counts check
    expect($counts['all'])->toBe(2)
        ->and($counts['not_started'])->toBe(1)
        ->and($counts['in_progress'])->toBe(1);

    $items = collect($paginator->items());
    $row1 = $items->firstWhere('id', $employee1->id);
    $row2 = $items->firstWhere('id', $unstartedEmployee->id);

    expect($row1['process']['status'])->toBe('awaiting_employee_signature')
        ->and($row1['process']['waiting_for'])->toBe($employee1->name)
        ->and($row2['process']['status'])->toBe('not_generated')
        ->and($row2['process']['label'])->toBe('Not started');
});

test('process filter filters roster correctly to in_progress and not_started', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $template = $fixtures['template'];
    $templateVersion = $fixtures['instance']->templateVersion;
    $user = User::factory()->create();

    $employee1 = $fixtures['employee'];
    $instance1 = $fixtures['instance'];
    $version1 = $fixtures['version'];

    $signingFlow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance1->id,
        'starting_document_instance_version_id' => $version1->id,
        'preset_name_snapshot' => 'Standard signing',
        'routing_definition_snapshot' => ['schema_version' => 1],
        'started_by' => $user->id,
        'started_at' => now(),
        'status' => 'active',
    ]);

    DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance1->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'source_document_instance_version_id' => $version1->id,
        'document_signing_flow_id' => $signingFlow->id,
        'status' => 'active',
        'stage' => 'signing',
        'policy_snapshot' => ['schema_version' => 1],
    ]);

    $unstartedEmployee = Employee::query()
        ->where('company_id', $company->id)
        ->where('id', '!=', $employee1->id)
        ->firstOrFail();

    // Filter in_progress
    $inProgressPaginator = CustomDocumentRosterQuery::paginate(
        companyId: $company->id,
        template: $template,
        version: $templateVersion,
        filters: new EmployeeDirectoryFilters,
        perPage: 25,
        filter: 'in_progress',
    );

    expect($inProgressPaginator->total())->toBe(1)
        ->and($inProgressPaginator->items()[0]['id'])->toBe($employee1->id);

    // Filter not_started
    $notStartedPaginator = CustomDocumentRosterQuery::paginate(
        companyId: $company->id,
        template: $template,
        version: $templateVersion,
        filters: new EmployeeDirectoryFilters,
        perPage: 25,
        filter: 'not_started',
    );

    expect($notStartedPaginator->total())->toBe(1)
        ->and($notStartedPaginator->items()[0]['id'])->toBe($unstartedEmployee->id);
});
