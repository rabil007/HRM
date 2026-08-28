<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Exceptions\DocumentWorkflowException;
use App\Models\Company;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Workflow\Actions\CompleteDocumentWorkflowTask;
use App\Support\Documents\Workflow\Actions\CreateDocumentWorkflowRequest;
use App\Support\Documents\Workflow\Actions\RejectDocumentWorkflowTask;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../../Support/document-fixtures.php';

function addCompanyMembership(User $user, Company $company): void
{
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
}

function giveCompanyPermission(User $user, Company $company, string $permission): void
{
    addCompanyMembership($user, $company);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->givePermissionTo(Permission::query()->firstOrCreate([
        'name' => $permission,
        'guard_name' => 'web',
    ]));
}

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     document: EmployeeDocument,
 *     instance: DocumentInstance,
 *     version: DocumentInstanceVersion,
 *     template: DocumentGenerationTemplate,
 * }
 */
function makeGeneratedDocumentWorkflowFixtures(?Company $company = null): array
{
    $company ??= makeDocumentFixtures()['company'];
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $templateVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $templateVersion->id]);

    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/letter.pdf";
    $canonicalPath = "document-instances/{$company->id}/canonical.pdf";
    Storage::disk('local')->put($libraryPath, '%PDF-1.4 test');
    Storage::disk('local')->put($canonicalPath, '%PDF-1.4 test');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Generated Letter',
        'file_path' => $libraryPath,
        'original_filename' => 'letter.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'abc',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'employee_no_snapshot' => $employee->employee_no,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => $templateVersion->version,
        'title_snapshot' => 'Generated Letter',
        'status' => 'generated',
        'employee_document_id' => $document->id,
        'generated_at' => now(),
    ]);

    $version = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'file_path' => $canonicalPath,
        'original_filename' => 'canonical.pdf',
        'size_bytes' => 100,
        'checksum' => 'abc',
    ]);

    $instance->update(['current_version_id' => $version->id]);

    return compact('company', 'employee', 'document', 'instance', 'version', 'template');
}

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
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $company->id, 'user_id' => $member->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        );
    }

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
    $approver = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.requests.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.review');

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

    $this->actingAs($approver)
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
