<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;
use App\Support\Documents\MyTasks\MyTasksCounter;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    fakeEmployeeFileDisks();
});

test('requests page defaults assigned_to_me to true on initial entry', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/requests/index')
            ->where('tab', 'review')
            ->where('filters.assigned_to_me', true));
});

test('requests page respects explicit assigned_to_me filter', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests', [
            'tab' => 'review',
            'assigned_to_me' => '0',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/requests/index')
            ->where('tab', 'review')
            ->where('filters.assigned_to_me', false));
});

test('my tasks counter counts active pending review tasks and awaiting recipient requests', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $instance = $fixtures['instance'];
    $version = $fixtures['version'];

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    // 1. Workflow task for user (Pending) -> should count
    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => 'pending',
        'requested_by' => $user->id,
        'requester_name_snapshot' => $user->name,
        'requested_at' => now(),
    ]);

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
        'assignee_user_id' => $user->id,
        'assignee_name_snapshot' => $user->name,
    ]);

    // 2. Workflow task for otherUser -> should NOT count for user
    DocumentWorkflowTask::query()->create([
        'company_id' => $company->id,
        'document_workflow_stage_id' => $stage->id,
        'status' => DocumentWorkflowTaskStatus::Pending,
        'assignee_user_id' => $otherUser->id,
        'assignee_name_snapshot' => $otherUser->name,
    ]);

    // 3. Recipient request for user (AwaitingAction) -> should count
    DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'employee_id' => $employee->id,
        'source_document_instance_version_id' => $version->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::CompanyUser,
        'recipient_role' => DocumentRecipientRole::Manager,
        'recipient_user_id' => $user->id,
        'recipient_name_snapshot' => $user->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(7),
        'requested_at' => now(),
        'source_checksum_sha256' => $version->checksum,
    ]);

    // 4. Recipient request for other user -> should NOT count
    DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'employee_id' => $employee->id,
        'source_document_instance_version_id' => $version->id,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::CompanyUser,
        'recipient_role' => DocumentRecipientRole::CompanySignatory,
        'recipient_user_id' => $otherUser->id,
        'recipient_name_snapshot' => $otherUser->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'expires_at' => now()->addDays(7),
        'requested_at' => now(),
        'source_checksum_sha256' => $version->checksum,
    ]);

    $counter = new MyTasksCounter;
    $countForUser = $counter->count($user, $company->id);
    $countForOther = $counter->count($otherUser, $company->id);

    // User has 1 review task + 1 recipient request = 2
    expect($countForUser)->toBe(2)
        // Other user has 1 review task + 1 recipient request = 2
        ->and($countForOther)->toBe(2);
});

test('my tasks count is shared in inertia auth props', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $company = $fixtures['company'];

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.my_tasks_count'));
});
