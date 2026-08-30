<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Jobs\GenerateCustomDocumentsJob;
use App\Models\Company;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Documents\CustomTemplatePdfRenderer;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplateAutomation;
use App\Support\Documents\Lifecycle\Actions\AdvanceDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\CreateDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\RetryDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StartDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StopDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\SyncDocumentLifecycleFromSigningFlow;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationGuard;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPresenter;
use App\Support\Documents\Lifecycle\ReconcileDocumentLifecycleAutomations;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Workflow\Actions\DeleteDocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\StoreDocumentWorkflowPreset;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';
require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     document: EmployeeDocument,
 *     instance: DocumentInstance,
 *     version: DocumentInstanceVersion,
 *     template: DocumentGenerationTemplate,
 *     templateVersion: DocumentGenerationTemplateVersion,
 *     initiator: User
 * }
 */
function makeLifecycleFixtures(): array
{
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $initiator = User::factory()->create();
    addCompanyMembership($initiator, $fixtures['company']);

    $templateVersion = DocumentGenerationTemplateVersion::query()
        ->whereKey($fixtures['instance']->document_generation_template_version_id)
        ->firstOrFail();

    return [
        ...$fixtures,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ];
}

test('create returns null when template version has no automation presets', function () {
    [
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $result = app(CreateDocumentLifecycleAutomation::class)->handle(
        $instance,
        $version,
        $templateVersion,
        $initiator->id,
    );

    expect($result)->toBeNull()
        ->and(DocumentLifecycleAutomation::query()->count())->toBe(0);
});

test('create is idempotent for the same document instance', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Lifecycle Preset',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    DocumentGenerationTemplateVersion::query()
        ->whereKey($templateVersion->id)
        ->update(['document_workflow_preset_id' => $preset->id]);
    $templateVersion->refresh();

    $first = app(CreateDocumentLifecycleAutomation::class)->handle(
        $instance,
        $version,
        $templateVersion,
        $initiator->id,
    );

    $second = app(CreateDocumentLifecycleAutomation::class)->handle(
        $instance,
        $version,
        $templateVersion,
        $initiator->id,
    );

    expect($first)->not->toBeNull()
        ->and($second->id)->toBe($first->id)
        ->and(DocumentLifecycleAutomation::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'document_lifecycle_started')->count())->toBe(1);
});

test('start blocks when initiator is missing', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
    ] = makeLifecycleFixtures();

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Missing Initiator Preset',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => User::factory()->create()->id,
    ]);
    DocumentGenerationTemplateVersion::query()
        ->whereKey($templateVersion->id)
        ->update(['document_workflow_preset_id' => $preset->id]);
    $templateVersion->refresh();

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $preset->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $preset->id,
            'workflow_preset_name' => $preset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Pending,
        'initiated_by' => null,
    ]);

    $result = app(StartDocumentLifecycleAutomation::class)->handle((int) $lifecycle->id, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Blocked)
        ->and($result->blocked_code)->toBe(DocumentLifecycleAutomationPolicy::BLOCK_MISSING_INITIATOR)
        ->and(Activity::query()->where('event', 'document_lifecycle_blocked')->exists())->toBeTrue();
});

test('start creates workflow request from snapshotted preset', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
        'document' => $document,
    ] = makeLifecycleFixtures();

    $reviewer = User::factory()->create();
    addCompanyMembership($reviewer, $company);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($initiator, $company, 'documents.requests.create');

    $approver = User::factory()->create();
    addCompanyMembership($approver, $company);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $initiator,
        companyId: $company->id,
        name: 'Lifecycle Review',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $reviewer->id,
                ]],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ],
        ],
    );

    DocumentGenerationTemplateVersion::query()
        ->whereKey($templateVersion->id)
        ->update(['document_workflow_preset_id' => $preset->id]);
    $templateVersion->refresh();

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $preset->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $preset->id,
            'workflow_preset_name' => $preset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Pending,
        'initiated_by' => $initiator->id,
    ]);

    $result = app(StartDocumentLifecycleAutomation::class)->handle((int) $lifecycle->id, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Active)
        ->and($result->stage)->toBe(DocumentLifecycleAutomationStage::Review)
        ->and($result->document_workflow_request_id)->not->toBeNull();

    $workflow = DocumentWorkflowRequest::query()->find($result->document_workflow_request_id);

    expect($workflow)->not->toBeNull()
        ->and($workflow->document_instance_id)->toBe($instance->id)
        ->and($workflow->document_instance_version_id)->toBe($version->id)
        ->and($workflow->document_workflow_preset_id)->toBe($preset->id)
        ->and(Activity::query()->where('event', 'document_lifecycle_review_started')->exists())->toBeTrue();
});

test('guard blocks manual workflow and signing while lifecycle is active pending or blocked', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => 1,
            'workflow_preset_name' => 'Review',
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $guard = app(DocumentLifecycleAutomationGuard::class);

    expect(fn () => $guard->assertManualWorkflowAllowed($instance, (int) $company->id))
        ->toThrow(ValidationException::class);

    expect(fn () => $guard->assertManualSigningAllowed($instance, (int) $company->id))
        ->toThrow(ValidationException::class);

    $lifecycle->update([
        'status' => DocumentLifecycleAutomationStatus::Completed,
        'stage' => DocumentLifecycleAutomationStage::Done,
        'completed_at' => now(),
    ]);

    $guard->assertManualWorkflowAllowed($instance->fresh(), (int) $company->id);
    $guard->assertManualSigningAllowed($instance->fresh(), (int) $company->id);
});

test('stop marks lifecycle stopped on workflow rejection without starting signing', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Rejected,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_request_id' => $workflow->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => 1,
            'workflow_preset_name' => 'Review',
            'signing_preset_id' => 2,
            'signing_preset_name' => 'Sign',
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    app(StopDocumentLifecycleAutomation::class)->handleForWorkflowTerminal(
        (int) $workflow->id,
        (int) $company->id,
        DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_REJECTED,
    );

    $lifecycle->refresh();

    expect($lifecycle->status)->toBe(DocumentLifecycleAutomationStatus::Stopped)
        ->and($lifecycle->blocked_code)->toBe(DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_REJECTED)
        ->and($lifecycle->document_signing_flow_id)->toBeNull()
        ->and(Activity::query()->where('event', 'document_lifecycle_stopped')->exists())->toBeTrue();
});

test('sync mirrors signing flow terminal and blocked states', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_signing_preset_id' => null,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Lifecycle Sign',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
                'target_type' => 'subject_employee',
                'recipient_user_id' => null,
                'recipient_name' => 'Employee',
            ]],
        ],
        'status' => DocumentSigningFlowStatus::Completed,
        'current_step_sequence' => 1,
        'started_by' => $initiator->id,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_signing_flow_id' => $flow->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => 1,
            'signing_preset_name' => 'Sign',
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    app(SyncDocumentLifecycleFromSigningFlow::class)->handle((int) $flow->id, (int) $company->id);

    expect($lifecycle->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($lifecycle->fresh()->stage)->toBe(DocumentLifecycleAutomationStage::Done);

    $flow->update([
        'status' => DocumentSigningFlowStatus::Blocked,
        'blocked_reason' => 'Step expired',
        'blocked_at' => now(),
        'completed_at' => null,
    ]);
    $lifecycle->update([
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'completed_at' => null,
    ]);

    app(SyncDocumentLifecycleFromSigningFlow::class)->handle((int) $flow->id, (int) $company->id);

    expect($lifecycle->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Blocked)
        ->and($lifecycle->fresh()->blocked_message)->toBe('Step expired');
});

test('retry requires blocked status and restarts from snapshot', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    expect(fn () => app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id))
        ->toThrow(ValidationException::class);

    $lifecycle->update([
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
        'blocked_message' => 'Temporary routing failure',
        'blocked_at' => now(),
    ]);

    $result = app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle->fresh(), $initiator, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($result->stage)->toBe(DocumentLifecycleAutomationStage::Done)
        ->and(Activity::query()->where('event', 'document_lifecycle_retried')->exists())->toBeTrue();
});

test('presenter returns null or document show payload', function () {
    expect(app(DocumentLifecycleAutomationPresenter::class)->forDocumentShow(null))->toBeNull();

    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => 12,
            'workflow_preset_name' => 'HR Approval',
            'signing_preset_id' => 7,
            'signing_preset_name' => 'Employee → Manager',
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Could not start signing',
        'initiated_by' => $initiator->id,
        'blocked_at' => now(),
    ]);

    $presented = app(DocumentLifecycleAutomationPresenter::class)->forDocumentShow($lifecycle);

    expect($presented)->toMatchArray([
        'id' => $lifecycle->id,
        'status' => 'blocked',
        'status_label' => 'Blocked',
        'stage' => 'signing',
        'stage_label' => 'Signing',
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Could not start signing',
        'behavior_summary' => 'Generate → Review & Approval → Signing',
        'can_retry' => true,
    ])
        ->and($presented['policy_snapshot']['workflow_preset_name'])->toBe('HR Approval')
        ->and($presented['policy_snapshot']['signing_preset_id'])->toBe(7);
});

test('update template automation writes bindings onto draft version', function () {
    ['company' => $company] = makeDocumentFixtures();

    $actor = User::factory()->create();
    addCompanyMembership($actor, $company);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();
    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
    ]);
    $template->update(['published_version_id' => $published->id]);

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Template Automation Preset',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $actor->id,
    ]);

    $draft = app(UpdateDocumentGenerationTemplateAutomation::class)->handle(
        $template,
        [
            'document_workflow_preset_id' => $preset->id,
            'document_signing_preset_id' => null,
        ],
        $actor,
    );

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->document_workflow_preset_id)->toBe($preset->id)
        ->and($draft->document_signing_preset_id)->toBeNull()
        ->and(Activity::query()
            ->where('properties->action', 'template_automation_updated')
            ->exists())->toBeTrue();
});

test('branch draft copies workflow automation for content templates', function () {
    ['company' => $company] = makeDocumentFixtures();

    $actor = User::factory()->create();
    addCompanyMembership($actor, $company);

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Content Workflow',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $actor->id,
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->content()->create();
    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => '<p>Hello {{employee_name}}</p>',
        'document_workflow_preset_id' => $preset->id,
        'document_signing_preset_id' => null,
    ]);
    $template->update(['published_version_id' => $published->id]);

    $draft = app(BranchDocumentGenerationTemplateDraft::class)->handle($template, $actor->id);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->version)->toBe(2)
        ->and($draft->document_workflow_preset_id)->toBe($preset->id)
        ->and($draft->document_signing_preset_id)->toBeNull();
});

test('duplicate copies automation bindings from source version', function () {
    ['company' => $company] = makeDocumentFixtures();

    $actor = User::factory()->create();
    addCompanyMembership($actor, $company);

    $workflowPreset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Dup Workflow',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $actor->id,
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->content()->create();
    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => '<p>Copy me</p>',
        'document_workflow_preset_id' => $workflowPreset->id,
    ]);
    $template->update(['published_version_id' => $published->id]);

    $copy = app(DuplicateDocumentGenerationTemplate::class)->handle($template, $actor);
    $copyVersion = $copy->draftVersion ?? $copy->versions()->first();

    expect($copyVersion)->not->toBeNull()
        ->and($copyVersion->document_workflow_preset_id)->toBe($workflowPreset->id)
        ->and($copyVersion->document_signing_preset_id)->toBeNull();
});

test('workflow preset deletion is blocked when referenced by a template version', function () {
    ['company' => $company] = makeDocumentFixtures();

    $actor = User::factory()->create();
    addCompanyMembership($actor, $company);

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Referenced Preset',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $actor->id,
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();
    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'document_workflow_preset_id' => $preset->id,
    ]);

    expect(fn () => app(DeleteDocumentWorkflowPreset::class)->handle($preset, $actor, (int) $company->id))
        ->toThrow(ValidationException::class);

    expect(DocumentWorkflowPreset::query()->whereKey($preset->id)->exists())->toBeTrue();
});

test('cross-company preset is rejected when updating template automation', function () {
    ['company' => $company] = makeDocumentFixtures();
    ['company' => $otherCompany] = makeDocumentFixtures();

    $actor = User::factory()->create();
    addCompanyMembership($actor, $company);

    $foreignPreset = DocumentWorkflowPreset::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Preset',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $actor->id,
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();
    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
    ]);
    $template->update(['published_version_id' => $published->id]);

    expect(fn () => app(UpdateDocumentGenerationTemplateAutomation::class)->handle(
        $template,
        [
            'document_workflow_preset_id' => $foreignPreset->id,
            'document_signing_preset_id' => null,
        ],
        $actor,
    ))->toThrow(ValidationException::class);
});

test('publish requires signature placement for signing automation', function () {
    ['company' => $company] = makeDocumentFixtures();

    $actor = User::factory()->create();
    addCompanyMembership($actor, $company);

    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $actor,
        $company->id,
        'Subject only',
        null,
        [['recipient_role' => 'subject']],
    );

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $draft = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
        'signature_placement_config' => null,
        'document_signing_preset_id' => $signingPreset->id,
    ]);

    expect(fn () => app(PublishDocumentGenerationTemplateVersion::class)->handle($draft, $actor->id))
        ->toThrow(DomainException::class);
});

test('create lifecycle after generation succeeds when template has automation', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Generation Lifecycle',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    DocumentGenerationTemplateVersion::query()
        ->whereKey($templateVersion->id)
        ->update(['document_workflow_preset_id' => $preset->id]);
    $templateVersion->refresh();

    $lifecycle = app(CreateDocumentLifecycleAutomation::class)->handle(
        $instance,
        $version,
        $templateVersion,
        $initiator->id,
    );

    expect($lifecycle)->not->toBeNull()
        ->and($lifecycle->document_instance_id)->toBe($instance->id)
        ->and($lifecycle->document_workflow_preset_id)->toBe($preset->id)
        ->and($lifecycle->policy_snapshot['workflow_preset_id'])->toBe($preset->id)
        ->and($lifecycle->status)->not->toBe(DocumentLifecycleAutomationStatus::Completed);
});

test('approval does not start signing when document current version diverged', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'document' => $document,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $workflowPreset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Exact Version Gate',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $initiator,
        $company->id,
        'Subject sign',
        null,
        [['recipient_role' => 'subject']],
    );

    DocumentGenerationTemplateVersion::query()
        ->whereKey($templateVersion->id)
        ->update([
            'document_workflow_preset_id' => $workflowPreset->id,
            'document_signing_preset_id' => $signingPreset->id,
        ]);
    $templateVersion->refresh();

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_signing_preset_id' => $signingPreset->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $workflowPreset->id,
            'workflow_preset_name' => $workflowPreset->name,
            'signing_preset_id' => $signingPreset->id,
            'signing_preset_name' => $signingPreset->name,
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $workflowRequest = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => now(),
        'document_workflow_preset_id' => $workflowPreset->id,
        'preset_name_snapshot' => $workflowPreset->name,
    ]);

    $lifecycle->update(['document_workflow_request_id' => $workflowRequest->id]);

    $newerVersion = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 2,
        'stage' => 'generated',
        'file_path' => $version->file_path,
        'original_filename' => 'v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'checksum' => hash('sha256', 'newer'),
        'created_by' => $initiator->id,
    ]);
    $instance->update(['current_version_id' => $newerVersion->id]);

    app(AdvanceDocumentLifecycleAutomation::class)->handleForApprovedWorkflow(
        (int) $workflowRequest->id,
        (int) $company->id,
    );

    $lifecycle->refresh();

    expect($lifecycle->status)->toBe(DocumentLifecycleAutomationStatus::Blocked)
        ->and($lifecycle->blocked_code)->toBe(DocumentLifecycleAutomationPolicy::BLOCK_SOURCE_VERSION_CHANGED)
        ->and($lifecycle->document_signing_flow_id)->toBeNull()
        ->and(DocumentSigningFlow::query()->where('document_instance_id', $instance->id)->count())->toBe(0);
});

test('generation registers lifecycle atomically with the document instance', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    addCompanyMembership($user, $company);
    giveCompanyPermission($user, $company, 'documents.requests.create');

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $reviewer = User::factory()->create();
    addCompanyMembership($reviewer, $company);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');

    $approver = User::factory()->create();
    addCompanyMembership($approver, $company);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $user,
        companyId: $company->id,
        name: 'Atomic Lifecycle Review',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $reviewer->id,
                ]],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ],
        ],
    );

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'name' => 'Atomic Lifecycle Letter',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Hello {{employee_name}}',
        'document_workflow_preset_id' => $preset->id,
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'lifecycle-atomic',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
    $mockRenderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    $instance = DocumentInstance::query()->findOrFail($item->document_instance_id);
    $lifecycle = DocumentLifecycleAutomation::query()
        ->forCompany($company->id)
        ->where('document_instance_id', $instance->id)
        ->first();

    expect($item->status)->toBe('completed')
        ->and($instance->currentVersion)->not->toBeNull()
        ->and($lifecycle)->not->toBeNull();

    expect(fn () => app(DocumentLifecycleAutomationGuard::class)->assertManualWorkflowAllowed($instance, (int) $company->id))
        ->toThrow(ValidationException::class);
});

test('lifecycle registration failure rolls back generation and compensates files', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    addCompanyMembership($user, $company);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Fail Registration',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $user->id,
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Hello {{employee_name}}',
        'document_workflow_preset_id' => $preset->id,
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'lifecycle-fail-reg',
        'triggered_by' => $user->id,
    ]);

    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    DocumentLifecycleAutomation::creating(function (): void {
        throw new RuntimeException('lifecycle write failed');
    });

    try {
        $mockRenderer = Mockery::mock(CustomTemplatePdfRenderer::class);
        $mockRenderer->shouldReceive('render')->once()->andReturn(minimalPdfBytes());

        $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
        $job->handle($mockRenderer, app(SyncGeneratedEmployeeDocument::class));
    } finally {
        DocumentLifecycleAutomation::getEventDispatcher()?->forget(
            'eloquent.creating: '.DocumentLifecycleAutomation::class,
        );
    }

    $item->refresh();

    expect($item->status)->toBe('failed')
        ->and(DocumentInstance::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(DocumentLifecycleAutomation::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(EmployeeDocument::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("document-instances/{$company->id}"))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles("employee-documents/{$company->id}/{$employee->id}"))->toBeEmpty();
});

test('start blocks review when current version diverged from lifecycle source', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    giveCompanyPermission($initiator, $company, 'documents.requests.create');

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Review Version Gate',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $preset->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $preset->id,
            'workflow_preset_name' => $preset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Pending,
        'initiated_by' => $initiator->id,
    ]);

    $newerVersion = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 2,
        'stage' => 'generated',
        'file_path' => $version->file_path,
        'original_filename' => 'v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'checksum' => hash('sha256', 'review-gate-v2'),
        'created_by' => $initiator->id,
    ]);
    $instance->update(['current_version_id' => $newerVersion->id]);

    $result = app(StartDocumentLifecycleAutomation::class)->handle((int) $lifecycle->id, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Blocked)
        ->and($result->blocked_code)->toBe(DocumentLifecycleAutomationPolicy::BLOCK_SOURCE_VERSION_CHANGED)
        ->and($result->document_workflow_request_id)->toBeNull()
        ->and(DocumentWorkflowRequest::query()->where('document_instance_id', $instance->id)->count())->toBe(0);
});

test('retry before workflow creation respects exact source version gate', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    giveCompanyPermission($initiator, $company, 'documents.requests.create');

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Retry Version Gate',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $preset->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $preset->id,
            'workflow_preset_name' => $preset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
        'blocked_message' => 'Temporary',
        'blocked_at' => now(),
        'initiated_by' => $initiator->id,
    ]);

    $newerVersion = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 2,
        'stage' => 'generated',
        'file_path' => $version->file_path,
        'original_filename' => 'v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'checksum' => hash('sha256', 'retry-gate-v2'),
        'created_by' => $initiator->id,
    ]);
    $instance->update(['current_version_id' => $newerVersion->id]);

    $result = app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Blocked)
        ->and($result->blocked_code)->toBe(DocumentLifecycleAutomationPolicy::BLOCK_SOURCE_VERSION_CHANGED)
        ->and(DocumentWorkflowRequest::query()->where('document_instance_id', $instance->id)->count())->toBe(0);
});

test('retry completes when approved workflow has no signing configured', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $workflowPreset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Approved No Signing',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    $workflowRequest = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => now(),
        'document_workflow_preset_id' => $workflowPreset->id,
        'preset_name_snapshot' => $workflowPreset->name,
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_workflow_request_id' => $workflowRequest->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $workflowPreset->id,
            'workflow_preset_name' => $workflowPreset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
        'blocked_message' => 'Post-approval glitch',
        'blocked_at' => now(),
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $result = app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($result->stage)->toBe(DocumentLifecycleAutomationStage::Done)
        ->and($result->document_signing_flow_id)->toBeNull()
        ->and(DocumentSigningFlow::query()->count())->toBe(0);
});

test('retry starts signing once for approved workflow with signing configured', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    Storage::disk('local')->put($version->file_path, minimalPdfBytes());

    giveCompanyPermission($initiator, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($initiator, $company, 'documents.signing-presets.create');

    $workflowPreset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Approved With Signing',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $initiator,
        $company->id,
        'Subject sign',
        null,
        [['recipient_role' => 'subject']],
    );

    DocumentGenerationTemplateVersion::query()
        ->whereKey($templateVersion->id)
        ->update(['signature_placement_config' => defaultSignaturePlacementConfig()]);
    $templateVersion->refresh();

    $workflowRequest = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => now(),
        'document_workflow_preset_id' => $workflowPreset->id,
        'preset_name_snapshot' => $workflowPreset->name,
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_signing_preset_id' => $signingPreset->id,
        'document_workflow_request_id' => $workflowRequest->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $workflowPreset->id,
            'workflow_preset_name' => $workflowPreset->name,
            'signing_preset_id' => $signingPreset->id,
            'signing_preset_name' => $signingPreset->name,
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Signing start failed',
        'blocked_at' => now(),
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $first = app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id);

    expect($first->status)->toBe(DocumentLifecycleAutomationStatus::Active)
        ->and($first->stage)->toBe(DocumentLifecycleAutomationStage::Signing)
        ->and($first->document_signing_flow_id)->not->toBeNull()
        ->and(DocumentSigningFlow::query()->where('document_instance_id', $instance->id)->count())->toBe(1);

    $again = app(AdvanceDocumentLifecycleAutomation::class)->startSnapshottedSigning($first->fresh(), (int) $company->id);

    expect($again->document_signing_flow_id)->toBe($first->document_signing_flow_id)
        ->and(DocumentSigningFlow::query()->where('document_instance_id', $instance->id)->count())->toBe(1);
});

test('retry recovers active and completed signing flows without creating duplicates', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_signing_preset_id' => null,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Active Flow',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
                'target_type' => 'subject_employee',
                'recipient_user_id' => null,
                'recipient_name' => 'Employee',
            ]],
        ],
        'status' => DocumentSigningFlowStatus::Active,
        'current_step_sequence' => 1,
        'started_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_signing_flow_id' => $flow->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => 1,
            'signing_preset_name' => 'Active Flow',
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Stale blocked',
        'blocked_at' => now(),
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $synced = app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id);

    expect($synced->status)->toBe(DocumentLifecycleAutomationStatus::Active)
        ->and($synced->stage)->toBe(DocumentLifecycleAutomationStage::Signing)
        ->and(DocumentSigningFlow::query()->count())->toBe(1);

    $flow->update([
        'status' => DocumentSigningFlowStatus::Completed,
        'completed_at' => now(),
    ]);

    DocumentLifecycleAutomation::query()->whereKey($synced->id)->update([
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Stale after completion',
        'blocked_at' => now(),
    ]);

    $completed = app(RetryDocumentLifecycleAutomation::class)->handle(
        DocumentLifecycleAutomation::query()->findOrFail($synced->id),
        $initiator,
        (int) $company->id,
    );

    expect($completed->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($completed->stage)->toBe(DocumentLifecycleAutomationStage::Done)
        ->and(DocumentSigningFlow::query()->count())->toBe(1);
});

test('retry cannot reactivate cancelled signing flow', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_signing_preset_id' => null,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Cancelled Flow',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
                'target_type' => 'subject_employee',
                'recipient_user_id' => null,
                'recipient_name' => 'Employee',
            ]],
        ],
        'status' => DocumentSigningFlowStatus::Cancelled,
        'current_step_sequence' => 1,
        'started_by' => $initiator->id,
        'started_at' => now(),
        'cancelled_at' => now(),
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_signing_flow_id' => $flow->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => 1,
            'signing_preset_name' => 'Cancelled Flow',
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Was blocked',
        'blocked_at' => now(),
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    $result = app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id);

    expect($result->status)->toBe(DocumentLifecycleAutomationStatus::Stopped)
        ->and(app(DocumentLifecycleAutomationPresenter::class)->forDocumentShow($result)['can_retry'])->toBeFalse();
});

test('retry rejects non-retryable blocked signing flow and presenter hides retry', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
        'employee' => $employee,
    ] = makeLifecycleFixtures();

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_signing_preset_id' => null,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Expired Flow',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
                'target_type' => 'subject_employee',
                'recipient_user_id' => null,
                'recipient_name' => 'Employee',
            ]],
        ],
        'status' => DocumentSigningFlowStatus::Blocked,
        'current_step_sequence' => 1,
        'started_by' => $initiator->id,
        'started_at' => now(),
        'blocked_at' => now(),
        'blocked_reason' => 'Step expired',
    ]);

    DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_signing_flow_id' => $flow->id,
        'signing_step_sequence' => 1,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => 'subject_employee',
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $employee->id,
        'recipient_name_snapshot' => $employee->name,
        'status' => DocumentRecipientRequestStatus::Expired,
        'token_hash' => hash('sha256', 'lifecycle-expired-token'),
        'expires_at' => now()->subMinute(),
        'requested_by' => $initiator->id,
        'requested_at' => now(),
        'source_checksum_sha256' => hash('sha256', 'source'),
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_signing_flow_id' => $flow->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => 1,
            'signing_preset_name' => 'Expired Flow',
        ],
        'status' => DocumentLifecycleAutomationStatus::Blocked,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
        'blocked_message' => 'Step expired',
        'blocked_at' => now(),
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    expect(app(DocumentLifecycleAutomationPresenter::class)->forDocumentShow($lifecycle)['can_retry'])->toBeFalse();

    expect(fn () => app(RetryDocumentLifecycleAutomation::class)->handle($lifecycle, $initiator, (int) $company->id))
        ->toThrow(ValidationException::class);

    expect($lifecycle->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Blocked)
        ->and($flow->fresh()->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and(DocumentSigningFlow::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'document_lifecycle_retried')->exists())->toBeFalse();
});

test('reconciliation starts pending lifecycle and is idempotent', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
        'document' => $document,
    ] = makeLifecycleFixtures();

    $reviewer = User::factory()->create();
    addCompanyMembership($reviewer, $company);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($initiator, $company, 'documents.requests.create');

    $approver = User::factory()->create();
    addCompanyMembership($approver, $company);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $initiator,
        companyId: $company->id,
        name: 'Reconcile Review',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $reviewer->id,
                ]],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ],
        ],
    );

    DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $preset->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $preset->id,
            'workflow_preset_name' => $preset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Pending,
        'initiated_by' => $initiator->id,
    ]);

    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => $company->id])
        ->assertSuccessful();

    $lifecycle = DocumentLifecycleAutomation::query()
        ->where('document_instance_id', $instance->id)
        ->firstOrFail();

    expect($lifecycle->status)->toBe(DocumentLifecycleAutomationStatus::Active)
        ->and($lifecycle->stage)->toBe(DocumentLifecycleAutomationStage::Review)
        ->and($lifecycle->document_workflow_request_id)->not->toBeNull()
        ->and(DocumentWorkflowRequest::query()->where('document_instance_id', $instance->id)->count())->toBe(1);

    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => $company->id])
        ->assertSuccessful();

    expect(DocumentWorkflowRequest::query()->where('document_instance_id', $instance->id)->count())->toBe(1)
        ->and($lifecycle->fresh()->document_workflow_request_id)->toBe($lifecycle->document_workflow_request_id);
});

test('reconciliation synchronizes approved rejected and cancelled workflow terminals', function () {
    [
        'company' => $company,
        'instance' => $instance,
        'version' => $version,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $workflowPreset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Terminal Sync',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    foreach ([
        [DocumentWorkflowRequestStatus::Approved, DocumentLifecycleAutomationStatus::Completed],
        [DocumentWorkflowRequestStatus::Rejected, DocumentLifecycleAutomationStatus::Stopped],
        [DocumentWorkflowRequestStatus::Cancelled, DocumentLifecycleAutomationStatus::Stopped],
    ] as [$workflowStatus, $expectedLifecycleStatus]) {
        $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
        $document = EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'type' => 'other',
            'document_type' => 'other',
            'title' => 'Doc',
            'file_path' => "employee-documents/{$company->id}/{$employee->id}/x.pdf",
            'original_filename' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'checksum' => hash('sha256', $workflowStatus->value),
            'current_version' => 1,
            'status' => 'valid',
        ]);
        Storage::disk('local')->put($document->file_path, '%PDF');

        $docInstance = DocumentInstance::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'employee_name_snapshot' => $employee->name,
            'employee_no_snapshot' => $employee->employee_no,
            'document_generation_template_id' => $instance->document_generation_template_id,
            'document_generation_template_version_id' => $templateVersion->id,
            'template_name_snapshot' => 'Doc',
            'template_version_number' => 1,
            'title_snapshot' => 'Doc',
            'status' => 'generated',
            'employee_document_id' => $document->id,
            'generated_at' => now(),
        ]);

        $docVersion = DocumentInstanceVersion::query()->create([
            'company_id' => $company->id,
            'document_instance_id' => $docInstance->id,
            'version' => 1,
            'stage' => 'generated',
            'file_path' => "document-instances/{$company->id}/".uniqid('v', true).'.pdf',
            'original_filename' => 'v1.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'checksum' => hash('sha256', uniqid('c', true)),
            'created_by' => $initiator->id,
        ]);
        Storage::disk('local')->put($docVersion->file_path, '%PDF');
        $docInstance->update(['current_version_id' => $docVersion->id]);

        $workflowRequest = DocumentWorkflowRequest::query()->create([
            'company_id' => $company->id,
            'document_instance_id' => $docInstance->id,
            'document_instance_version_id' => $docVersion->id,
            'status' => $workflowStatus,
            'requested_by' => $initiator->id,
            'requester_name_snapshot' => $initiator->name,
            'requested_at' => now(),
            'completed_at' => now(),
            'document_workflow_preset_id' => $workflowPreset->id,
            'preset_name_snapshot' => $workflowPreset->name,
        ]);

        DocumentLifecycleAutomation::query()->create([
            'company_id' => $company->id,
            'document_instance_id' => $docInstance->id,
            'source_document_instance_version_id' => $docVersion->id,
            'document_generation_template_version_id' => $templateVersion->id,
            'document_workflow_preset_id' => $workflowPreset->id,
            'document_workflow_request_id' => $workflowRequest->id,
            'policy_snapshot' => [
                'schema_version' => 1,
                'workflow_preset_id' => $workflowPreset->id,
                'workflow_preset_name' => $workflowPreset->name,
                'signing_preset_id' => null,
                'signing_preset_name' => null,
            ],
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Review,
            'initiated_by' => $initiator->id,
            'started_at' => now(),
        ]);

        expect($expectedLifecycleStatus)->not->toBeNull();
    }

    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => $company->id])
        ->assertSuccessful();

    $statuses = DocumentLifecycleAutomation::query()
        ->where('company_id', $company->id)
        ->where('document_instance_id', '!=', $instance->id)
        ->pluck('status')
        ->map(fn ($status) => $status->value)
        ->sort()
        ->values()
        ->all();

    expect($statuses)->toBe(['completed', 'stopped', 'stopped']);
});

test('reconciliation is company scoped', function () {
    [
        'company' => $companyA,
        'instance' => $instanceA,
        'version' => $versionA,
        'templateVersion' => $templateVersionA,
        'initiator' => $initiatorA,
    ] = makeLifecycleFixtures();

    [
        'company' => $companyB,
        'instance' => $instanceB,
        'version' => $versionB,
        'templateVersion' => $templateVersionB,
        'initiator' => $initiatorB,
    ] = makeLifecycleFixtures();

    DocumentLifecycleAutomation::query()->create([
        'company_id' => $companyA->id,
        'document_instance_id' => $instanceA->id,
        'source_document_instance_version_id' => $versionA->id,
        'document_generation_template_version_id' => $templateVersionA->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Pending,
        'initiated_by' => $initiatorA->id,
    ]);

    DocumentLifecycleAutomation::query()->create([
        'company_id' => $companyB->id,
        'document_instance_id' => $instanceB->id,
        'source_document_instance_version_id' => $versionB->id,
        'document_generation_template_version_id' => $templateVersionB->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Pending,
        'initiated_by' => $initiatorB->id,
    ]);

    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => $companyA->id])
        ->assertSuccessful();

    expect(DocumentLifecycleAutomation::query()->where('company_id', $companyA->id)->first()->status)
        ->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and(DocumentLifecycleAutomation::query()->where('company_id', $companyB->id)->first()->status)
        ->toBe(DocumentLifecycleAutomationStatus::Pending);
});

function seedActiveReviewLifecycleForReconcile(
    Company $company,
    User $initiator,
    DocumentGenerationTemplate $template,
    DocumentGenerationTemplateVersion $templateVersion,
    DocumentWorkflowPreset $preset,
    DocumentWorkflowRequestStatus $workflowStatus,
): DocumentLifecycleAutomation {
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $pdfBytes = '%PDF-1.4 test';
    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/".uniqid('lib-', true).'.pdf';
    $canonicalPath = "document-instances/{$company->id}/".uniqid('can-', true).'.pdf';
    Storage::disk('local')->put($libraryPath, $pdfBytes);
    Storage::disk('local')->put($canonicalPath, $pdfBytes);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Reconcile Doc',
        'file_path' => $libraryPath,
        'original_filename' => 'doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes.uniqid('', true)),
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
        'title_snapshot' => 'Reconcile Doc',
        'status' => 'generated',
        'employee_document_id' => $document->id,
        'generated_at' => now(),
    ]);

    $version = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'stage' => 'generated',
        'file_path' => $canonicalPath,
        'original_filename' => 'canonical.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes.uniqid('v', true)),
        'created_by' => $initiator->id,
    ]);
    $instance->update(['current_version_id' => $version->id]);

    $workflowRequest = DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => $workflowStatus,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => $workflowStatus === DocumentWorkflowRequestStatus::Pending ? null : now(),
        'document_workflow_preset_id' => $preset->id,
        'preset_name_snapshot' => $preset->name,
    ]);

    return DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_workflow_preset_id' => $preset->id,
        'document_workflow_request_id' => $workflowRequest->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => $preset->id,
            'workflow_preset_name' => $preset->name,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);
}

/**
 * @return array{lifecycle: DocumentLifecycleAutomation, flow: DocumentSigningFlow}
 */
function seedActiveSigningLifecycleForReconcile(
    Company $company,
    User $initiator,
    DocumentGenerationTemplate $template,
    DocumentGenerationTemplateVersion $templateVersion,
    DocumentSigningFlowStatus $flowStatus,
    DocumentLifecycleAutomationStatus $lifecycleStatus = DocumentLifecycleAutomationStatus::Active,
): array {
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $pdfBytes = '%PDF-1.4 test';
    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/".uniqid('slib-', true).'.pdf';
    $canonicalPath = "document-instances/{$company->id}/".uniqid('scan-', true).'.pdf';
    Storage::disk('local')->put($libraryPath, $pdfBytes);
    Storage::disk('local')->put($canonicalPath, $pdfBytes);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Signing Reconcile Doc',
        'file_path' => $libraryPath,
        'original_filename' => 'doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes.uniqid('', true)),
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
        'title_snapshot' => 'Signing Reconcile Doc',
        'status' => 'generated',
        'employee_document_id' => $document->id,
        'generated_at' => now(),
    ]);

    $version = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'stage' => 'generated',
        'file_path' => $canonicalPath,
        'original_filename' => 'canonical.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes.uniqid('sv', true)),
        'created_by' => $initiator->id,
    ]);
    $instance->update(['current_version_id' => $version->id]);

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_signing_preset_id' => null,
        'starting_document_instance_version_id' => $version->id,
        'preset_name_snapshot' => 'Reconcile Sign',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
                'target_type' => 'subject_employee',
                'recipient_user_id' => null,
                'recipient_name' => 'Employee',
            ]],
        ],
        'status' => $flowStatus,
        'current_step_sequence' => 1,
        'started_by' => $initiator->id,
        'started_at' => now(),
        'completed_at' => $flowStatus === DocumentSigningFlowStatus::Completed ? now() : null,
        'cancelled_at' => $flowStatus === DocumentSigningFlowStatus::Cancelled ? now() : null,
        'blocked_at' => $flowStatus === DocumentSigningFlowStatus::Blocked ? now() : null,
        'blocked_reason' => $flowStatus === DocumentSigningFlowStatus::Blocked ? 'Step expired' : null,
    ]);

    $lifecycle = DocumentLifecycleAutomation::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'source_document_instance_version_id' => $version->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'document_signing_flow_id' => $flow->id,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => 1,
            'signing_preset_name' => 'Reconcile Sign',
        ],
        'status' => $lifecycleStatus,
        'stage' => DocumentLifecycleAutomationStage::Signing,
        'blocked_code' => $lifecycleStatus === DocumentLifecycleAutomationStatus::Blocked
            ? DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED
            : null,
        'blocked_message' => $lifecycleStatus === DocumentLifecycleAutomationStatus::Blocked
            ? 'Step expired'
            : null,
        'blocked_at' => $lifecycleStatus === DocumentLifecycleAutomationStatus::Blocked ? now() : null,
        'initiated_by' => $initiator->id,
        'started_at' => now(),
    ]);

    return ['lifecycle' => $lifecycle, 'flow' => $flow];
}

test('review reconciliation is not starved by pending workflows ahead of terminals', function () {
    [
        'company' => $company,
        'template' => $template,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $preset = DocumentWorkflowPreset::query()->create([
        'company_id' => $company->id,
        'name' => 'Starvation Review Preset',
        'status' => DocumentWorkflowPresetStatus::Active,
        'created_by' => $initiator->id,
    ]);

    $pendingCount = ReconcileDocumentLifecycleAutomations::BATCH_LIMIT + 1;

    for ($i = 0; $i < $pendingCount; $i++) {
        seedActiveReviewLifecycleForReconcile(
            $company,
            $initiator,
            $template,
            $templateVersion,
            $preset,
            DocumentWorkflowRequestStatus::Pending,
        );
    }

    $approved = seedActiveReviewLifecycleForReconcile(
        $company,
        $initiator,
        $template,
        $templateVersion,
        $preset,
        DocumentWorkflowRequestStatus::Approved,
    );

    $rejected = seedActiveReviewLifecycleForReconcile(
        $company,
        $initiator,
        $template,
        $templateVersion,
        $preset,
        DocumentWorkflowRequestStatus::Rejected,
    );

    $reconciler = app(ReconcileDocumentLifecycleAutomations::class);
    $synced = $reconciler->reconcileActiveReviews((int) $company->id);

    expect($synced)->toBe(2)
        ->and($approved->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($approved->fresh()->stage)->toBe(DocumentLifecycleAutomationStage::Done)
        ->and($rejected->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Stopped)
        ->and(DocumentLifecycleAutomation::query()
            ->where('company_id', $company->id)
            ->where('status', DocumentLifecycleAutomationStatus::Active)
            ->where('stage', DocumentLifecycleAutomationStage::Review)
            ->count())->toBe($pendingCount);

    expect($reconciler->reconcileActiveReviews((int) $company->id))->toBe(0);
});

test('signing reconciliation is not starved by healthy active flows', function () {
    [
        'company' => $company,
        'template' => $template,
        'templateVersion' => $templateVersion,
        'initiator' => $initiator,
    ] = makeLifecycleFixtures();

    $healthyCount = ReconcileDocumentLifecycleAutomations::BATCH_LIMIT + 1;
    $healthyLifecycleIds = [];

    for ($i = 0; $i < $healthyCount; $i++) {
        $seeded = seedActiveSigningLifecycleForReconcile(
            $company,
            $initiator,
            $template,
            $templateVersion,
            DocumentSigningFlowStatus::Active,
        );
        $healthyLifecycleIds[] = $seeded['lifecycle']->id;
    }

    $stale = seedActiveSigningLifecycleForReconcile(
        $company,
        $initiator,
        $template,
        $templateVersion,
        DocumentSigningFlowStatus::Completed,
        DocumentLifecycleAutomationStatus::Active,
    );

    $reconciler = app(ReconcileDocumentLifecycleAutomations::class);
    $synced = $reconciler->reconcileSigning((int) $company->id);

    expect($synced)->toBe(1)
        ->and($stale['lifecycle']->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($stale['lifecycle']->fresh()->stage)->toBe(DocumentLifecycleAutomationStage::Done);

    foreach ($healthyLifecycleIds as $lifecycleId) {
        expect(DocumentLifecycleAutomation::query()->findOrFail($lifecycleId)->status)
            ->toBe(DocumentLifecycleAutomationStatus::Active);
    }

    expect($reconciler->reconcileSigning((int) $company->id))->toBe(0);
});

test('reconcile command rejects invalid company option', function () {
    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => 'abc'])
        ->assertFailed();

    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => '0'])
        ->assertFailed();

    $this->artisan('documents:reconcile-lifecycle-automations', ['--company' => '-3'])
        ->assertFailed();
});
