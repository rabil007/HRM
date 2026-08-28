<?php

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Exceptions\DocumentWorkflowException;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Workflow\Actions\CancelDocumentWorkflowRequest;
use App\Support\Documents\Workflow\Actions\CompleteDocumentWorkflowTask;
use App\Support\Documents\Workflow\Actions\CreateDocumentWorkflowRequest;
use App\Support\Documents\Workflow\Actions\RejectDocumentWorkflowTask;
use App\Support\Documents\Workflow\DocumentWorkflowEligibility;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

test('users without documents.requests.create cannot create workflow requests', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [User::factory()->create()->id],
            ]],
        ])
        ->assertForbidden();
});

test('creates workflow request bound to current document instance version', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);

    foreach ([$reviewer, $approver] as $member) {
        addCompanyMembership($member, $company);
    }

    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [
                [
                    'action' => 'review',
                    'completion_rule' => 'all',
                    'assignee_user_ids' => [$reviewer->id],
                ],
                [
                    'action' => 'approve',
                    'completion_rule' => 'any',
                    'assignee_user_ids' => [$approver->id],
                ],
            ],
        ])
        ->assertRedirect();

    $workflow = DocumentWorkflowRequest::query()->first();

    expect($workflow)->not->toBeNull()
        ->and($workflow->document_instance_id)->toBe($instance->id)
        ->and($workflow->document_instance_version_id)->toBe($version->id)
        ->and($workflow->status)->toBe(DocumentWorkflowRequestStatus::Pending);

    expect(DocumentWorkflowStage::query()->count())->toBe(2)
        ->and(DocumentWorkflowStage::query()->where('sequence', 1)->first()?->status)
        ->toBe(DocumentWorkflowStageStatus::Active);
});

test('rejects cross company assignees when creating workflow request', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();
    $otherCompany = makeDocumentFixtures()['company'];

    $requester = User::factory()->create();
    $outsider = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    grantCompanyPermissions($outsider, $otherCompany, ['documents.requests.approve']);

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$outsider->id],
            ]],
        ])
        ->assertSessionHasErrors('stages');
});

test('prevents duplicate active workflow requests for the same version', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    addCompanyMembership($reviewer, $company);
    giveCompanyPermission($reviewer, $company, 'documents.requests.approve');

    DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $requester->id,
        'requester_name_snapshot' => $requester->name,
        'requested_at' => now(),
    ]);

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$reviewer->id],
            ]],
        ])
        ->assertSessionHasErrors('document');
});

test('blocks self approval on assigned tasks', function () {
    ['company' => $company, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.review', 'documents.requests.approve']);

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $requester->id,
        'requester_name_snapshot' => $requester->name,
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

    $task = DocumentWorkflowTask::query()->create([
        'company_id' => $company->id,
        'document_workflow_stage_id' => $stage->id,
        'assignee_user_id' => $requester->id,
        'assignee_name_snapshot' => $requester->name,
        'status' => DocumentWorkflowTaskStatus::Pending,
    ]);

    expect(fn () => app(CompleteDocumentWorkflowTask::class)->handle($task, $requester, $company->id))
        ->toThrow(DocumentWorkflowException::class);
});

test('all completion rule waits for every task and any rejection rejects workflow', function () {
    ['company' => $company, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewerA = User::factory()->create();
    $reviewerB = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($reviewerA, $company, 'documents.requests.review');
    giveCompanyPermission($reviewerA, $company, 'documents.requests.approve');
    giveCompanyPermission($reviewerB, $company, 'documents.requests.review');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: EmployeeDocument::query()->whereKey($instance->employee_document_id)->firstOrFail(),
        stages: [[
            'action' => 'review',
            'completion_rule' => 'all',
            'assignee_user_ids' => [$reviewerA->id, $reviewerB->id],
        ], [
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$reviewerA->id],
        ]],
    );

    $taskA = DocumentWorkflowTask::query()->where('assignee_user_id', $reviewerA->id)->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($taskA, $reviewerA, $company->id);

    expect($workflow->fresh()->status)->toBe(DocumentWorkflowRequestStatus::Pending);

    $taskB = DocumentWorkflowTask::query()->where('assignee_user_id', $reviewerB->id)->firstOrFail();
    app(RejectDocumentWorkflowTask::class)->handle($taskB, $reviewerB, $company->id, 'Not acceptable');

    expect($workflow->fresh()->status)->toBe(DocumentWorkflowRequestStatus::Rejected);
});

test('any completion rule completes stage on first approval and skips remaining tasks', function () {
    ['company' => $company, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approverA = User::factory()->create();
    $approverB = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($approverA, $company, 'documents.requests.approve');
    giveCompanyPermission($approverB, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: EmployeeDocument::query()->whereKey($instance->employee_document_id)->firstOrFail(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approverA->id, $approverB->id],
        ]],
    );

    $taskA = DocumentWorkflowTask::query()->where('assignee_user_id', $approverA->id)->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($taskA, $approverA, $company->id);

    $taskB = DocumentWorkflowTask::query()->where('assignee_user_id', $approverB->id)->firstOrFail();

    expect($workflow->fresh()->status)->toBe(DocumentWorkflowRequestStatus::Approved)
        ->and($taskB->fresh()->status)->toBe(DocumentWorkflowTaskStatus::Skipped);
});

test('review permission cannot complete approval tasks', function () {
    ['company' => $company, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $requester->id,
        'requester_name_snapshot' => $requester->name,
        'requested_at' => now(),
    ]);

    $stage = DocumentWorkflowStage::query()->create([
        'company_id' => $company->id,
        'document_workflow_request_id' => $workflow->id,
        'sequence' => 1,
        'action' => DocumentWorkflowAction::Approve,
        'completion_rule' => DocumentWorkflowCompletionRule::Any,
        'status' => DocumentWorkflowStageStatus::Active,
        'started_at' => now(),
    ]);

    $task = DocumentWorkflowTask::query()->create([
        'company_id' => $company->id,
        'document_workflow_stage_id' => $stage->id,
        'assignee_user_id' => $reviewer->id,
        'assignee_name_snapshot' => $reviewer->name,
        'status' => DocumentWorkflowTaskStatus::Pending,
    ]);

    $this->actingAs($reviewer)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-tasks.complete', ['workflowTask' => $task->id]))
        ->assertForbidden();
});

test('workflow remains bound to original version when instance current version changes', function () {
    ['company' => $company, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: EmployeeDocument::query()->whereKey($instance->employee_document_id)->firstOrFail(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $versionTwo = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 2,
        'file_path' => "document-instances/{$company->id}/canonical-v2.pdf",
        'original_filename' => 'canonical-v2.pdf',
        'size_bytes' => 100,
        'checksum' => 'def',
    ]);
    $instance->update(['current_version_id' => $versionTwo->id]);

    expect($workflow->fresh()->document_instance_version_id)->toBe($version->id);
});

test('duplicate task completion is rejected', function () {
    ['company' => $company, 'instance' => $instance] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: EmployeeDocument::query()->whereKey($instance->employee_document_id)->firstOrFail(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $task = DocumentWorkflowTask::query()->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($task, $approver, $company->id);

    expect(fn () => app(CompleteDocumentWorkflowTask::class)->handle($task->fresh(), $approver, $company->id))
        ->toThrow(DocumentWorkflowException::class);
});

test('workflow version preview serves bound canonical bytes after library replacement', function () {
    ['company' => $company, 'document' => $document, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    Storage::disk('local')->put($version->file_path, '%PDF-1.4 canonical-v1');
    Storage::disk('local')->put($document->file_path, '%PDF-1.4 library-original');

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.view']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    Storage::disk('local')->put($document->file_path, '%PDF-1.4 library-replaced');

    $response = $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests.version-preview', ['workflowRequest' => $workflow->id]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->streamedContent())->toBe('%PDF-1.4 canonical-v1');
});

test('workflow version preview still works when employee document library row is deleted', function () {
    ['company' => $company, 'document' => $document, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    Storage::disk('local')->put($version->file_path, '%PDF-1.4 canonical-only');

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.view']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $document->delete();

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests.version-preview', ['workflowRequest' => $workflow->id]))
        ->assertOk();
});

test('workflow version preview rejects cross company access', function () {
    ['company' => $company, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();
    $otherCompany = makeDocumentFixtures()['company'];

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.view']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $outsider = User::factory()->create();
    grantCompanyPermissions($outsider, $otherCompany, ['documents.requests.view']);

    $this->actingAs($outsider)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('organization.documents.requests.version-preview', ['workflowRequest' => $workflow->id]))
        ->assertNotFound();
});

test('workflow version preview rejects version not belonging to bound instance', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.view']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $otherInstance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $instance->employee_id,
        'employee_name_snapshot' => 'Other',
        'employee_no_snapshot' => 'E-2',
        'document_generation_template_id' => $instance->document_generation_template_id,
        'document_generation_template_version_id' => $instance->document_generation_template_version_id,
        'template_name_snapshot' => 'Other',
        'template_version_number' => 1,
        'title_snapshot' => 'Other',
        'status' => 'generated',
        'generated_at' => now(),
    ]);

    $foreignVersion = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $otherInstance->id,
        'version' => 1,
        'file_path' => "document-instances/{$company->id}/foreign.pdf",
        'original_filename' => 'foreign.pdf',
        'size_bytes' => 10,
        'checksum' => 'xyz',
    ]);
    Storage::disk('local')->put($foreignVersion->file_path, '%PDF foreign');

    $workflow->update(['document_instance_version_id' => $foreignVersion->id]);

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests.version-preview', ['workflowRequest' => $workflow->fresh()->id]))
        ->assertNotFound();
});

test('workflow version preview rejects unsafe canonical paths', function (string $unsafePath) {
    ['company' => $company, 'document' => $document, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();
    $otherCompany = makeDocumentFixtures()['company'];

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.view']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $resolvedPath = str_replace(
        ['{companyId}', '{otherCompanyId}'],
        [(string) $company->id, (string) $otherCompany->id],
        $unsafePath,
    );

    DB::table('document_instance_versions')
        ->where('id', $version->id)
        ->update(['file_path' => $resolvedPath]);

    if (! str_contains($resolvedPath, '..') && ! str_starts_with($resolvedPath, '/')) {
        Storage::disk('local')->put($resolvedPath, '%PDF-unsafe');
    }

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests.version-preview', ['workflowRequest' => $workflow->id]))
        ->assertNotFound();
})->with([
    'parent traversal' => ['document-instances/{companyId}/../{otherCompanyId}/evil.pdf'],
    'cross-company directory' => ['document-instances/{otherCompanyId}/evil.pdf'],
    'company id prefix bypass' => ['document-instances/{companyId}2/evil.pdf'],
    'absolute path' => ['/document-instances/{companyId}/evil.pdf'],
    'current-directory segment' => ['document-instances/{companyId}/./evil.pdf'],
]);

test('legacy home-company users without pivot appear in workflow assignee options when permitted', function () {
    ['company' => $company] = makeGeneratedDocumentWorkflowFixtures();

    $legacyUser = User::factory()->create([
        'status' => 'active',
        'company_id' => $company->id,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $legacyUser->givePermissionTo(Permission::query()->firstOrCreate([
        'name' => 'documents.requests.view',
        'guard_name' => 'web',
    ]));
    $legacyUser->givePermissionTo(Permission::query()->firstOrCreate([
        'name' => 'documents.requests.review',
        'guard_name' => 'web',
    ]));

    $options = app(DocumentWorkflowEligibility::class)->assigneeOptions($company->id);

    expect(collect($options)->pluck('id'))->toContain($legacyUser->id)
        ->and(collect($options)->firstWhere('id', $legacyUser->id))
        ->toMatchArray([
            'id' => $legacyUser->id,
            'name' => (string) $legacyUser->name,
            'email' => $legacyUser->email,
            'can_review' => true,
            'can_approve' => false,
        ]);
});

test('assignee options exclude users without review or approve capability', function () {
    ['company' => $company] = makeGeneratedDocumentWorkflowFixtures();

    $capable = User::factory()->create();
    $incapable = User::factory()->create();
    giveCompanyPermission($capable, $company, 'documents.requests.review');
    addCompanyMembership($incapable, $company);

    $options = app(DocumentWorkflowEligibility::class)->assigneeOptions($company->id);

    expect(collect($options)->pluck('id'))->toContain($capable->id)
        ->not->toContain($incapable->id);
});

test('document show omits assignee options when workflow cannot be created', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.requests.create']);

    DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $user->id,
        'requester_name_snapshot' => $user->name,
        'requested_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.employee.files.show', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflow.can_create', false)
            ->where('workflow.assignee_options', []));
});

test('document show loads assignee options only for eligible generated documents', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $viewer = User::factory()->create();
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    $incapable = User::factory()->create();

    grantCompanyPermissions($viewer, $company, ['documents.view', 'documents.requests.create']);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');
    addCompanyMembership($incapable, $company);

    $response = $this->actingAs($viewer)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.employee.files.show', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflow.can_create', true)
            ->has('workflow.assignee_options', 2));

    $assigneeIds = collect($response->original->getData()['page']['props']['workflow']['assignee_options'])
        ->pluck('id')
        ->all();

    expect($assigneeIds)->toContain($reviewer->id, $approver->id)
        ->not->toContain($incapable->id);
});

test('document show exposes a valid workflow summary show url', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.view', 'documents.requests.create', 'documents.requests.view']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $workflow = DocumentWorkflowRequest::query()->firstOrFail();

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.employee.files.show', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflow.summary.show_url', route('organization.documents.requests.show', ['workflowRequest' => $workflow->id])));

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.requests.show', ['workflowRequest' => $workflow->id]))
        ->assertOk();
});

test('rejects review stage assignees without review permission at creation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $assignee = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    addCompanyMembership($assignee, $company);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'review',
                'completion_rule' => 'all',
                'assignee_user_ids' => [$assignee->id],
            ], [
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$approver->id],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.assignee_user_ids');
});

test('rejects approval stage assignees without approve permission at creation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    addCompanyMembership($approver, $company);
    giveCompanyPermission($approver, $company, 'documents.requests.review');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'review',
                'completion_rule' => 'all',
                'assignee_user_ids' => [$reviewer->id],
            ], [
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$approver->id],
            ]],
        ])
        ->assertSessionHasErrors('stages.1.assignee_user_ids');
});

test('accepts correctly permitted assignees at creation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'review',
                'completion_rule' => 'all',
                'assignee_user_ids' => [$reviewer->id],
            ], [
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$approver->id],
            ]],
        ])
        ->assertRedirect();

    expect(DocumentWorkflowRequest::query()->count())->toBe(1);
});

test('rejects inactive assignees at creation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $inactive = User::factory()->create(['status' => 'inactive']);
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    addCompanyMembership($inactive, $company);

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$inactive->id],
            ]],
        ])
        ->assertSessionHasErrors('stages');
});

test('allows the same user in different workflow stages when permitted', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $dualRole = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($dualRole, $company, 'documents.requests.review');
    giveCompanyPermission($dualRole, $company, 'documents.requests.approve');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'review',
                'completion_rule' => 'all',
                'assignee_user_ids' => [$dualRole->id],
            ], [
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$dualRole->id],
            ]],
        ])
        ->assertRedirect();

    expect(DocumentWorkflowTask::query()->where('assignee_user_id', $dualRole->id)->count())->toBe(2);
});

test('rejects duplicate assignees within the same workflow stage', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'review',
                'completion_rule' => 'all',
                'assignee_user_ids' => [$reviewer->id, $reviewer->id],
            ], [
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$approver->id],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.assignee_user_ids');
});

test('decision after cancellation is rejected', function () {
    ['company' => $company, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.cancel']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $workflow = DocumentWorkflowRequest::query()->firstOrFail();
    $task = DocumentWorkflowTask::query()->firstOrFail();

    app(CancelDocumentWorkflowRequest::class)->handle($workflow, $requester, $company->id, 'No longer needed');

    expect(fn () => app(CompleteDocumentWorkflowTask::class)->handle($task->fresh(), $approver, $company->id))
        ->toThrow(DocumentWorkflowException::class);
});

test('cancellation after final approval is rejected', function () {
    ['company' => $company, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.cancel']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $workflow = DocumentWorkflowRequest::query()->firstOrFail();
    $task = DocumentWorkflowTask::query()->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($task, $approver, $company->id);

    expect($workflow->fresh()->status)->toBe(DocumentWorkflowRequestStatus::Approved);

    expect(fn () => app(CancelDocumentWorkflowRequest::class)->handle($workflow->fresh(), $requester, $company->id))
        ->toThrow(DocumentWorkflowException::class);
});

test('stale inactive stage decisions are rejected', function () {
    ['company' => $company, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => $requester->id,
        'requester_name_snapshot' => $requester->name,
        'requested_at' => now(),
    ]);

    $stage = DocumentWorkflowStage::query()->create([
        'company_id' => $company->id,
        'document_workflow_request_id' => $workflow->id,
        'sequence' => 1,
        'action' => DocumentWorkflowAction::Review,
        'completion_rule' => DocumentWorkflowCompletionRule::All,
        'status' => DocumentWorkflowStageStatus::Pending,
    ]);

    $task = DocumentWorkflowTask::query()->create([
        'company_id' => $company->id,
        'document_workflow_stage_id' => $stage->id,
        'assignee_user_id' => $reviewer->id,
        'assignee_name_snapshot' => $reviewer->name,
        'status' => DocumentWorkflowTaskStatus::Pending,
    ]);

    expect(fn () => app(CompleteDocumentWorkflowTask::class)->handle($task, $reviewer, $company->id))
        ->toThrow(DocumentWorkflowException::class);
});

test('workflow activity metadata does not duplicate decision free text', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $reviewer = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create', 'documents.requests.cancel']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');

    app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $approvedTask = DocumentWorkflowTask::query()->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($approvedTask, $approver, $company->id, 'Looks good to me');

    $versionTwo = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 2,
        'file_path' => "document-instances/{$company->id}/canonical-v2.pdf",
        'original_filename' => 'canonical-v2.pdf',
        'size_bytes' => 100,
        'checksum' => 'def',
    ]);
    Storage::disk('local')->put($versionTwo->file_path, '%PDF-2');
    $instance->update(['current_version_id' => $versionTwo->id]);

    $cancelWorkflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    app(CancelDocumentWorkflowRequest::class)->handle($cancelWorkflow->fresh(), $requester, $company->id, 'Changed mind');

    $versionThree = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 3,
        'file_path' => "document-instances/{$company->id}/canonical-v3.pdf",
        'original_filename' => 'canonical-v3.pdf',
        'size_bytes' => 100,
        'checksum' => 'ghi',
    ]);
    Storage::disk('local')->put($versionThree->file_path, '%PDF-3');
    $instance->update(['current_version_id' => $versionThree->id]);

    $rejectWorkflow = app(CreateDocumentWorkflowRequest::class)->handle(
        requester: $requester,
        companyId: $company->id,
        document: $document->fresh(),
        stages: [[
            'action' => 'review',
            'completion_rule' => 'all',
            'assignee_user_ids' => [$reviewer->id],
        ], [
            'action' => 'approve',
            'completion_rule' => 'any',
            'assignee_user_ids' => [$approver->id],
        ]],
    );

    $rejectTask = DocumentWorkflowTask::query()
        ->where('document_workflow_stage_id', $rejectWorkflow->stages()->where('sequence', 1)->value('id'))
        ->firstOrFail();

    app(RejectDocumentWorkflowTask::class)->handle($rejectTask, $reviewer, $company->id, 'Needs changes');

    $properties = Activity::query()
        ->where('company_id', $company->id)
        ->whereIn('event', ['approval_completed', 'workflow_cancelled', 'workflow_rejected', 'task_rejected'])
        ->get()
        ->map(fn (Activity $activity): array => $activity->properties->toArray())
        ->all();

    expect($properties)->not->toBeEmpty();

    foreach ($properties as $propertyBag) {
        expect($propertyBag)->not->toHaveKey('task_notes')
            ->and($propertyBag)->not->toHaveKey('cancel_reason');
    }
});
