<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\Process\DocumentOperationalProcessPresenter;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
});

function createTestRecipientRequest(
    array $fixtures,
    array $overrides = []
): DocumentRecipientRequest {
    return DocumentRecipientRequest::query()->create(array_merge([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'source_document_instance_version_id' => $fixtures['version']->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $fixtures['employee']->id,
        'recipient_name_snapshot' => $fixtures['employee']->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(14),
        'requested_at' => now(),
        'source_checksum_sha256' => $fixtures['version']->checksum,
    ], $overrides));
}

function createTestLifecycleAutomation(
    array $fixtures,
    array $overrides = []
): DocumentLifecycleAutomation {
    return DocumentLifecycleAutomation::query()->create(array_merge([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_generation_template_version_id' => $fixtures['template']->published_version_id,
        'source_document_instance_version_id' => $fixtures['version']->id,
        'status' => 'pending',
        'policy_snapshot' => ['schema_version' => 1],
    ], $overrides));
}

test('humanFailureMessage returns clear user-facing messages for failure categories', function () {
    expect(DocumentOperationalProcessPresenter::humanFailureMessage('recipient_email_missing'))
        ->toBe('Recipient email address is missing.')
        ->and(DocumentOperationalProcessPresenter::humanFailureMessage('email_transport'))
        ->toBe('Email delivery could not be sent. Please retry or re-send.')
        ->and(DocumentOperationalProcessPresenter::humanFailureMessage('recipient_no_longer_actionable'))
        ->toBe('Recipient is no longer actionable.')
        ->and(DocumentOperationalProcessPresenter::humanFailureMessage('access_token_revoked'))
        ->toBe('Access link has been regenerated.')
        ->and(DocumentOperationalProcessPresenter::humanFailureMessage('reminder_window_missed'))
        ->toBe('Scheduled reminder window was missed.');
});

test('presenter identifies not_generated state when no document or instance exists', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $employee = Employee::factory()->create(['company_id' => $fixtures['company']->id, 'status' => 'active']);

    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present($employee);

    expect($result['status'])->toBe('not_generated')
        ->and($result['label'])->toBe('Not started')
        ->and($result['tone'])->toBe('neutral')
        ->and($result['document_copy_email']['status'])->toBe('not_sent');
});

test('presenter identifies generating state when run item is processing', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $employee = $fixtures['employee'];
    $runItem = DocumentGenerationRunItem::factory()->create([
        'employee_id' => $employee->id,
        'status' => 'processing',
    ]);

    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present($employee, null, null, $runItem);

    expect($result['status'])->toBe('generating')
        ->and($result['label'])->toBe('Generating')
        ->and($result['tone'])->toBe('info');
});

test('presenter identifies failed state when run item failed', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $employee = $fixtures['employee'];
    $runItem = DocumentGenerationRunItem::factory()->create([
        'employee_id' => $employee->id,
        'status' => 'failed',
    ]);

    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present($employee, null, null, $runItem);

    expect($result['status'])->toBe('failed')
        ->and($result['label'])->toBe('Failed')
        ->and($result['tone'])->toBe('danger');
});

test('presenter identifies awaiting_approval with actor information', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];
    $reviewer = User::factory()->create(['name' => 'Sara Reviewer']);

    $lifecycle = createTestLifecycleAutomation($fixtures, ['stage' => 'review']);
    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $fixtures['version']->id,
        'status' => 'pending',
        'requested_by' => $reviewer->id,
        'requester_name_snapshot' => $reviewer->name,
        'requested_at' => now(),
    ]);
    $lifecycle->update(['document_workflow_request_id' => $workflow->id]);

    $stage = DocumentWorkflowStage::query()->create([
        'company_id' => $company->id,
        'document_workflow_request_id' => $workflow->id,
        'sequence' => 1,
        'action' => DocumentWorkflowAction::Review,
        'completion_rule' => DocumentWorkflowCompletionRule::All,
        'status' => DocumentWorkflowStageStatus::Active,
        'started_at' => now(),
    ]);

    DocumentWorkflowTask::query()->create([
        'company_id' => $company->id,
        'document_workflow_stage_id' => $stage->id,
        'status' => DocumentWorkflowTaskStatus::Pending,
        'assignee_user_id' => $reviewer->id,
        'assignee_name_snapshot' => $reviewer->name,
    ]);

    $instance->load([
        'lifecycleAutomation.workflowRequest.stages.tasks.assignee',
        'lifecycleAutomation.signingFlow.recipientRequests.deliveries',
        'recipientRequests.deliveries',
    ]);

    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present($employee, $instance);

    expect($result['status'])->toBe('awaiting_approval')
        ->and($result['label'])->toBe('Awaiting approval')
        ->and($result['tone'])->toBe('info')
        ->and($result['waiting_for'])->toContain('Sara Reviewer');
});

test('presenter identifies awaiting_employee_signature and awaiting_manager_signature states', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];
    $version = $fixtures['version'];

    $user = User::factory()->create();
    $lifecycle = createTestLifecycleAutomation($fixtures, ['stage' => 'signing']);
    $signingFlow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Standard signing',
        'routing_definition_snapshot' => ['schema_version' => 1],
        'started_by' => $user->id,
        'started_at' => now(),
        'status' => 'active',
    ]);
    $lifecycle->update(['document_signing_flow_id' => $signingFlow->id]);

    $recipient = createTestRecipientRequest($fixtures, [
        'document_signing_flow_id' => $signingFlow->id,
        'recipient_role' => DocumentRecipientRole::Subject,
        'recipient_name_snapshot' => 'John Employee',
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
    ]);

    $instance->load([
        'lifecycleAutomation.workflowRequest.stages.tasks',
        'lifecycleAutomation.signingFlow.recipientRequests.deliveries',
        'recipientRequests.deliveries',
    ]);

    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present($employee, $instance);

    expect($result['status'])->toBe('awaiting_employee_signature')
        ->and($result['label'])->toBe('Awaiting employee signature')
        ->and($result['tone'])->toBe('info')
        ->and($result['waiting_for'])->toContain('John Employee');

    // Switch role to manager
    $recipient->update([
        'recipient_role' => DocumentRecipientRole::Manager,
        'recipient_name_snapshot' => 'Jane Manager',
    ]);
    $instance->refresh();
    $instance->load([
        'lifecycleAutomation.workflowRequest.stages.tasks',
        'lifecycleAutomation.signingFlow.recipientRequests.deliveries',
        'recipientRequests.deliveries',
    ]);

    $resultManager = $presenter->present($employee, $instance);
    expect($resultManager['status'])->toBe('awaiting_manager_signature')
        ->and($resultManager['label'])->toBe('Awaiting manager signature')
        ->and($resultManager['waiting_for'])->toContain('Jane Manager');
});

test('presenter distinguishes manual copy emails from action emails', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];
    $doc = $fixtures['document'];

    $sentAt = Carbon::now()->subHours(2);
    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present(
        employee: $employee,
        instance: $instance,
        employeeDocument: $doc,
        copyEmailSentAt: $sentAt,
    );

    expect($result['status'])->toBe('generated')
        ->and($result['document_copy_email']['status'])->toBe('sent')
        ->and($result['document_copy_email']['sent_at'])->not->toBeNull();
});

test('presenter exposes action_email failure with human message when recipient delivery failed', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];

    $recipient = createTestRecipientRequest($fixtures, [
        'recipient_role' => DocumentRecipientRole::Subject,
        'recipient_name_snapshot' => 'John Employee',
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
    ]);

    DocumentRecipientRequestDelivery::query()->create([
        'company_id' => $company->id,
        'document_recipient_request_id' => $recipient->id,
        'channel' => 'email',
        'purpose' => 'initial',
        'delivery_sequence' => 1,
        'destination_snapshot' => 'john@example.com',
        'status' => 'failed',
        'failure_category' => 'email_transport',
    ]);

    $instance->load([
        'lifecycleAutomation.workflowRequest.stages.tasks',
        'lifecycleAutomation.signingFlow.recipientRequests.deliveries',
        'recipientRequests.deliveries',
    ]);

    $presenter = new DocumentOperationalProcessPresenter;
    $result = $presenter->present($employee, $instance);

    expect($result['action_email'])->not->toBeNull()
        ->and($result['action_email']['status'])->toBe('failed')
        ->and($result['action_email']['failure_category'])->toBe('email_transport')
        ->and($result['action_email']['failure_message'])->toBe('Email delivery could not be sent. Please retry or re-send.');
});
