<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\Company;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
});

test('unauthorized users cannot access document journey endpoint', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $instance = $fixtures['instance'];

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.journey', [
            'document_instance_id' => $instance->id,
        ]))
        ->assertForbidden();
});

test('users with bulk_documents.view can access document journey and view timeline events', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];
    $version = $fixtures['version'];

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    // Create workflow request & review task
    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => 'pending',
        'requested_by' => $user->id,
        'requester_name_snapshot' => $user->name,
        'requested_at' => now()->subHours(3),
    ]);

    $stage = DocumentWorkflowStage::query()->create([
        'company_id' => $company->id,
        'document_workflow_request_id' => $workflow->id,
        'sequence' => 1,
        'action' => DocumentWorkflowAction::Review,
        'completion_rule' => DocumentWorkflowCompletionRule::All,
        'status' => DocumentWorkflowStageStatus::Active,
        'started_at' => now()->subHours(3),
    ]);

    DocumentWorkflowTask::query()->create([
        'company_id' => $company->id,
        'document_workflow_stage_id' => $stage->id,
        'status' => DocumentWorkflowTaskStatus::Pending,
        'assignee_user_id' => $user->id,
        'assignee_name_snapshot' => $user->name,
    ]);

    // Create signing flow & recipient request
    $signingFlow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Standard signing',
        'routing_definition_snapshot' => ['schema_version' => 1],
        'started_by' => $user->id,
        'started_at' => now()->subHours(2),
        'status' => 'active',
    ]);

    $recipient = DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_signing_flow_id' => $signingFlow->id,
        'source_document_instance_version_id' => $version->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $employee->id,
        'recipient_name_snapshot' => $employee->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(14),
        'requested_at' => now()->subHours(2),
        'source_checksum_sha256' => $version->checksum,
    ]);

    // Link lifecycle automation
    DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_generation_template_version_id' => $fixtures['template']->published_version_id,
        'source_document_instance_version_id' => $version->id,
        'document_workflow_request_id' => $workflow->id,
        'document_signing_flow_id' => $signingFlow->id,
        'status' => 'active',
        'stage' => 'signing',
        'policy_snapshot' => ['schema_version' => 1],
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.journey', [
            'document_instance_id' => $instance->id,
        ]))
        ->assertOk();

    $json = $response->json();

    expect($json['employee']['id'])->toBe($employee->id)
        ->and($json['employee']['name'])->toBe($employee->name)
        ->and($json['process']['status'])->toBe('awaiting_employee_signature')
        ->and($json['process']['waiting_for'])->toBe($employee->name)
        ->and($json['events'])->toBeArray()
        ->and(count($json['events']))->toBeGreaterThanOrEqual(2);
});

test('document journey respects tenancy isolation', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $companyA = $fixtures['company'];
    $instance = $fixtures['instance'];

    // Different company
    ['company' => $companyB] = makeDocumentFixtures();
    $userB = User::factory()->create();
    grantCompanyPermissions($userB, $companyB, ['bulk_documents.view']);

    $this->actingAs($userB)
        ->withSession(['current_company_id' => $companyB->id])
        ->getJson(route('organization.documents.journey', [
            'document_instance_id' => $instance->id,
        ]))
        ->assertNotFound();
});

test('document journey surfaces delivery failure banner and human message', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];
    $version = $fixtures['version'];

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['bulk_documents.view']);

    $recipient = DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $employee->id,
        'recipient_name_snapshot' => $employee->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(14),
        'requested_at' => now()->subHours(1),
        'source_checksum_sha256' => $version->checksum,
    ]);

    DocumentRecipientRequestDelivery::query()->create([
        'company_id' => $company->id,
        'document_recipient_request_id' => $recipient->id,
        'channel' => 'email',
        'purpose' => 'initial',
        'delivery_sequence' => 1,
        'destination_snapshot' => 'employee@test.com',
        'status' => 'failed',
        'failure_category' => 'email_transport',
        'attempted_at' => now()->subMinutes(30),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.journey', [
            'document_instance_id' => $instance->id,
        ]))
        ->assertOk();

    $json = $response->json();

    expect($json['process']['action_email'])->not->toBeNull()
        ->and($json['process']['action_email']['status'])->toBe('failed')
        ->and($json['process']['action_email']['failure_category'])->toBe('email_transport')
        ->and($json['process']['action_email']['failure_message'])->toBe('Email delivery could not be sent. Please retry or re-send.');
});
