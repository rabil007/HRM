<?php

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\Company;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
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
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Workflow\Actions\DeleteDocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\StoreDocumentWorkflowPreset;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

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
