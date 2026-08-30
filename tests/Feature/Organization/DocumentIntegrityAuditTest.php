<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Jobs\GenerateCustomDocumentsJob;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentRecipientRequestEvent;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowTask;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Documents\CustomTemplatePdfRenderer;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\Documents\Integrity\DocumentIntegrityAudit;
use App\Support\Documents\Integrity\DocumentIntegrityIssue;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Workflow\Actions\CompleteDocumentWorkflowTask;
use App\Support\Documents\Workflow\Actions\StoreDocumentWorkflowPreset;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

/**
 * @return list<DocumentIntegrityIssue>
 */
function integrityIssuesByCode($result, string $code): array
{
    return array_values(array_filter(
        $result->issues(),
        fn (DocumentIntegrityIssue $issue): bool => $issue->code === $code,
    ));
}

function makeIntegrityRecipientRequest(array $fixtures, array $overrides = []): DocumentRecipientRequest
{
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

function makeIntegrityLifecycle(array $fixtures, array $overrides = []): DocumentLifecycleAutomation
{
    $templateVersion = DocumentGenerationTemplateVersion::query()
        ->whereKey($fixtures['instance']->document_generation_template_version_id)
        ->firstOrFail();

    return DocumentLifecycleAutomation::query()->create(array_merge([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'source_document_instance_version_id' => $fixtures['version']->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'policy_snapshot' => [
            'schema_version' => DocumentLifecycleAutomationPolicy::SCHEMA_VERSION,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'initiated_by' => User::factory()->create()->id,
        'started_at' => now(),
    ], $overrides));
}

function overlayIntegritySourcePdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage('P', [210, 297]);
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'OVERLAY SOURCE');

    return $pdf->Output('S');
}

test('healthy generated document reports no critical or high issues', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);

    expect($result->criticalCount())->toBe(0)
        ->and($result->highCount())->toBe(0)
        ->and($result->repaired())->toBe(0);
});

test('missing current version is detected as high', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $fixtures['instance']->update(['current_version_id' => null]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'instance_missing_current_version');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity->value)->toBe('high')
        ->and($issues[0]->repairable)->toBeFalse()
        ->and($issues[0]->entityId)->toBe($fixtures['instance']->id);
});

test('cross-company current version is detected as critical', function () {
    $companyA = makeGeneratedDocumentWorkflowFixtures();
    $companyB = makeGeneratedDocumentWorkflowFixtures();

    $companyA['instance']->update(['current_version_id' => $companyB['version']->id]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $companyA['company']->id);
    $issues = integrityIssuesByCode($result, 'instance_current_version_cross_company');

    expect($issues)->not->toBeEmpty()
        ->and($issues[0]->severity->value)->toBe('critical')
        ->and($issues[0]->repairable)->toBeFalse();

    expect(integrityIssuesByCode($result, 'instance_current_version_wrong_instance'))->not->toBeEmpty();
});

test('wrong employee document company is detected as critical', function () {
    $companyA = makeGeneratedDocumentWorkflowFixtures();
    $companyB = makeGeneratedDocumentWorkflowFixtures();

    $companyA['instance']->update(['employee_document_id' => $companyB['document']->id]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $companyA['company']->id);
    $issues = integrityIssuesByCode($result, 'instance_employee_document_cross_company');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity->value)->toBe('critical');
});

test('lifecycle source version mismatch is detected', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $other = makeGeneratedDocumentWorkflowFixtures($fixtures['company']);
    $lifecycle = makeIntegrityLifecycle($fixtures, [
        'source_document_instance_version_id' => $other['version']->id,
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'lifecycle_source_version_mismatch');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->entityId)->toBe($lifecycle->id)
        ->and($issues[0]->repairable)->toBeFalse();
});

test('lifecycle stale child state is marked repairable', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $initiator = User::factory()->create();
    addCompanyMembership($initiator, $fixtures['company']);

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_instance_version_id' => $fixtures['version']->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);

    $lifecycle = makeIntegrityLifecycle($fixtures, [
        'document_workflow_request_id' => $workflow->id,
        'initiated_by' => $initiator->id,
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
        'policy_snapshot' => [
            'schema_version' => 1,
            'workflow_preset_id' => null,
            'workflow_preset_name' => null,
            'signing_preset_id' => null,
            'signing_preset_name' => null,
        ],
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'lifecycle_stale_workflow_state');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->repairable)->toBeTrue()
        ->and($issues[0]->severity->value)->toBe('warning')
        ->and($lifecycle->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Active);
});

test('signing-flow version mismatch is detected', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $other = makeGeneratedDocumentWorkflowFixtures($fixtures['company']);
    $starter = User::factory()->create();

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'starting_document_instance_version_id' => $other['version']->id,
        'preset_name_snapshot' => 'Subject only',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
            ]],
        ],
        'status' => DocumentSigningFlowStatus::Active,
        'current_step_sequence' => 1,
        'started_by' => $starter->id,
        'started_at' => now(),
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'signing_flow_starting_version_mismatch');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->entityId)->toBe($flow->id)
        ->and($issues[0]->repairable)->toBeFalse();
});

test('completed SIGN without result version is detected as high', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $request = makeIntegrityRecipientRequest($fixtures, [
        'action' => DocumentRecipientAction::Sign,
        'status' => DocumentRecipientRequestStatus::Completed,
        'completed_at' => now(),
        'result_document_instance_version_id' => null,
        'next_reminder_at' => null,
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'recipient_sign_completed_missing_result');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity->value)->toBe('high')
        ->and($issues[0]->entityId)->toBe($request->id);
});

test('completed ACK with result version is detected as high', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $request = makeIntegrityRecipientRequest($fixtures, [
        'action' => DocumentRecipientAction::Acknowledge,
        'status' => DocumentRecipientRequestStatus::Completed,
        'completed_at' => now(),
        'result_document_instance_version_id' => $fixtures['version']->id,
        'next_reminder_at' => null,
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'recipient_ack_completed_has_result');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity->value)->toBe('high')
        ->and($issues[0]->entityId)->toBe($request->id);
});

test('terminal recipient next_reminder_at is detected as a repairable warning', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $request = makeIntegrityRecipientRequest($fixtures, [
        'status' => DocumentRecipientRequestStatus::Completed,
        'action' => DocumentRecipientAction::Acknowledge,
        'completed_at' => now(),
        'next_reminder_at' => now()->addDay(),
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    $issues = integrityIssuesByCode($result, 'recipient_terminal_has_reminder');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->repairable)->toBeTrue()
        ->and($issues[0]->severity->value)->toBe('warning')
        ->and($request->fresh()->next_reminder_at)->not->toBeNull();
});

test('repair-safe clears terminal reminder pointer', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $request = makeIntegrityRecipientRequest($fixtures, [
        'status' => DocumentRecipientRequestStatus::Expired,
        'next_reminder_at' => now()->addDay(),
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle(
        (int) $fixtures['company']->id,
        verifyFiles: false,
        repairSafe: true,
    );

    expect($result->repaired())->toBe(1)
        ->and($request->fresh()->next_reminder_at)->toBeNull()
        ->and(Activity::query()->where('event', 'document_integrity_repaired')->count())->toBe(1);
});

test('repair-safe lifecycle sync uses existing logic', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $initiator = User::factory()->create();
    addCompanyMembership($initiator, $fixtures['company']);

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_instance_version_id' => $fixtures['version']->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => $initiator->id,
        'requester_name_snapshot' => $initiator->name,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);

    $lifecycle = makeIntegrityLifecycle($fixtures, [
        'document_workflow_request_id' => $workflow->id,
        'initiated_by' => $initiator->id,
        'status' => DocumentLifecycleAutomationStatus::Active,
        'stage' => DocumentLifecycleAutomationStage::Review,
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle(
        (int) $fixtures['company']->id,
        verifyFiles: false,
        repairSafe: true,
    );

    expect($result->repaired())->toBeGreaterThanOrEqual(1)
        ->and($lifecycle->fresh()->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($lifecycle->fresh()->stage)->toBe(DocumentLifecycleAutomationStage::Done);
});

test('repair-safe is tenant isolated', function () {
    $companyA = makeGeneratedDocumentWorkflowFixtures();
    $companyB = makeGeneratedDocumentWorkflowFixtures();

    $requestA = makeIntegrityRecipientRequest($companyA, [
        'status' => DocumentRecipientRequestStatus::Cancelled,
        'cancelled_at' => now(),
        'next_reminder_at' => now()->addDay(),
    ]);
    $requestB = makeIntegrityRecipientRequest($companyB, [
        'status' => DocumentRecipientRequestStatus::Cancelled,
        'cancelled_at' => now(),
        'next_reminder_at' => now()->addDay(),
    ]);

    $result = app(DocumentIntegrityAudit::class)->handle(
        (int) $companyA['company']->id,
        verifyFiles: false,
        repairSafe: true,
    );

    expect($result->repaired())->toBe(1)
        ->and($requestA->fresh()->next_reminder_at)->toBeNull()
        ->and($requestB->fresh()->next_reminder_at)->not->toBeNull();
});

test('missing file is detected only with file verification', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    Storage::disk('local')->delete($fixtures['version']->file_path);

    $metadata = app(DocumentIntegrityAudit::class)->handle((int) $fixtures['company']->id);
    expect(integrityIssuesByCode($metadata, 'file_canonical_missing'))->toBeEmpty();

    $withFiles = app(DocumentIntegrityAudit::class)->handle(
        (int) $fixtures['company']->id,
        verifyFiles: true,
    );

    expect(integrityIssuesByCode($withFiles, 'file_canonical_missing'))->toHaveCount(1)
        ->and(integrityIssuesByCode($withFiles, 'file_canonical_missing')[0]->severity->value)->toBe('warning');
});

test('invalid company option is rejected', function () {
    $this->artisan('documents:audit-integrity', ['--company' => '0'])
        ->expectsOutput('The --company option must be a positive integer.')
        ->assertFailed();

    $this->artisan('documents:audit-integrity', ['--company' => '-3'])
        ->expectsOutput('The --company option must be a positive integer.')
        ->assertFailed();

    $this->artisan('documents:audit-integrity', ['--company' => 'abc'])
        ->expectsOutput('The --company option must be a positive integer.')
        ->assertFailed();
});

test('audit does not mutate by default', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $request = makeIntegrityRecipientRequest($fixtures, [
        'status' => DocumentRecipientRequestStatus::Completed,
        'action' => DocumentRecipientAction::Acknowledge,
        'completed_at' => now(),
        'next_reminder_at' => now()->addDay(),
    ]);

    $this->artisan('documents:audit-integrity', ['--company' => (string) $fixtures['company']->id])
        ->assertSuccessful();

    expect($request->fresh()->next_reminder_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'document_integrity_repaired')->count())->toBe(0);
});

test('audit command output does not include raw tokens or sensitive data', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $rawToken = 'raw-token-canary-value-do-not-print';
    $email = 'canary-recipient@example.test';

    $request = makeIntegrityRecipientRequest($fixtures, [
        'token_hash' => hash('sha256', $rawToken),
        'status' => DocumentRecipientRequestStatus::Completed,
        'action' => DocumentRecipientAction::Acknowledge,
        'completed_at' => now(),
        'next_reminder_at' => now()->addDay(),
        'signed_name' => 'Canary Signed Name',
    ]);

    DocumentRecipientRequestDelivery::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_recipient_request_id' => $request->id,
        'channel' => DocumentRecipientRequestDeliveryChannel::Email,
        'purpose' => DocumentRecipientRequestDeliveryPurpose::Initial,
        'delivery_sequence' => 1,
        'destination_snapshot' => $email,
        'template_slug' => 'document_recipient_action_request',
        'status' => DocumentRecipientRequestDeliveryStatus::Sent,
    ]);

    $exit = Artisan::call('documents:audit-integrity', ['--company' => (string) $fixtures['company']->id]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Critical:')
        ->and($output)->toContain('Warning:')
        ->and($output)->not->toContain($rawToken)
        ->and($output)->not->toContain($email)
        ->and($output)->not->toContain('Canary Signed Name')
        ->and($output)->not->toContain((string) $request->token_hash);
});

test('deleting library document preserves operational document history', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $actor = User::factory()->create();
    addCompanyMembership($actor, $fixtures['company']);

    $workflow = DocumentWorkflowRequest::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_instance_version_id' => $fixtures['version']->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => $actor->id,
        'requester_name_snapshot' => $actor->name,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);

    $lifecycle = makeIntegrityLifecycle($fixtures, [
        'document_workflow_request_id' => $workflow->id,
        'status' => DocumentLifecycleAutomationStatus::Completed,
        'stage' => DocumentLifecycleAutomationStage::Done,
        'completed_at' => now(),
    ]);

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'starting_document_instance_version_id' => $fixtures['version']->id,
        'preset_name_snapshot' => 'Subject',
        'routing_definition_snapshot' => ['schema_version' => 1, 'steps' => [['sequence' => 1, 'recipient_role' => 'subject']]],
        'status' => DocumentSigningFlowStatus::Completed,
        'current_step_sequence' => 1,
        'started_by' => $actor->id,
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $request = makeIntegrityRecipientRequest($fixtures, [
        'document_signing_flow_id' => $flow->id,
        'signing_step_sequence' => 1,
        'status' => DocumentRecipientRequestStatus::Completed,
        'action' => DocumentRecipientAction::Acknowledge,
        'completed_at' => now(),
        'next_reminder_at' => null,
    ]);

    $event = DocumentRecipientRequestEvent::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_recipient_request_id' => $request->id,
        'event' => DocumentRecipientRequestEventType::AcknowledgementSubmitted,
        'occurred_at' => now(),
    ]);

    $delivery = DocumentRecipientRequestDelivery::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_recipient_request_id' => $request->id,
        'channel' => DocumentRecipientRequestDeliveryChannel::Email,
        'purpose' => DocumentRecipientRequestDeliveryPurpose::Initial,
        'delivery_sequence' => 1,
        'status' => DocumentRecipientRequestDeliveryStatus::Sent,
    ]);

    $canonicalPath = $fixtures['version']->file_path;

    app(DocumentDeletionService::class)->delete($fixtures['document']);

    expect(DocumentInstance::query()->find($fixtures['instance']->id))->not->toBeNull()
        ->and(DocumentInstanceVersion::query()->find($fixtures['version']->id))->not->toBeNull()
        ->and(DocumentWorkflowRequest::query()->find($workflow->id))->not->toBeNull()
        ->and(DocumentLifecycleAutomation::query()->find($lifecycle->id))->not->toBeNull()
        ->and(DocumentSigningFlow::query()->find($flow->id))->not->toBeNull()
        ->and(DocumentRecipientRequest::query()->find($request->id))->not->toBeNull()
        ->and(DocumentRecipientRequestEvent::query()->find($event->id))->not->toBeNull()
        ->and(DocumentRecipientRequestDelivery::query()->find($delivery->id))->not->toBeNull()
        ->and($fixtures['instance']->fresh()->employee_document_id)->toBeNull()
        ->and(Storage::disk('local')->exists($canonicalPath))->toBeTrue();
});

test('overlay generation through review signing and audit stays healthy', function () {
    $user = User::factory()->create();
    $fixtures = makeDocumentFixtures();
    $company = $fixtures['company'];
    addCompanyMembership($user, $company);
    giveCompanyPermission($user, $company, 'documents.requests.create');
    giveCompanyPermission($user, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($user, $company, 'documents.signing-presets.create');

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    addCompanyMembership($reviewer, $company);
    addCompanyMembership($approver, $company);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $workflowPreset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $user,
        companyId: $company->id,
        name: 'Integrity Review',
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

    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Subject only',
        null,
        [['recipient_role' => 'subject']],
    );

    $sourcePath = "document-generation-templates/{$company->id}/overlay-integrity.pdf";
    Storage::disk('local')->put($sourcePath, overlayIntegritySourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'name' => 'Integrity Overlay',
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $sourcePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
        'signature_placement_config' => defaultSignaturePlacementConfig(),
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_signing_preset_id' => $signingPreset->id,
    ]);
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'queued',
        'total_targeted' => 1,
        'correlation_id' => 'integrity-overlay-e2e',
        'triggered_by' => $user->id,
    ]);
    $item = DocumentGenerationRunItem::query()->create([
        'company_id' => $company->id,
        'document_generation_run_id' => $run->id,
        'employee_id' => $employee->id,
        'status' => 'pending',
    ]);

    $job = new GenerateCustomDocumentsJob($company->id, $user->id, $run->id, false);
    $job->handle(app(CustomTemplatePdfRenderer::class), app(SyncGeneratedEmployeeDocument::class));

    $item->refresh();
    expect($item->status)->toBe('completed');

    $instance = DocumentInstance::query()->findOrFail($item->document_instance_id);
    $lifecycle = DocumentLifecycleAutomation::query()
        ->where('document_instance_id', $instance->id)
        ->firstOrFail();

    expect($lifecycle->status)->toBe(DocumentLifecycleAutomationStatus::Active)
        ->and($lifecycle->stage)->toBe(DocumentLifecycleAutomationStage::Review);

    $reviewTask = DocumentWorkflowTask::query()
        ->where('assignee_user_id', $reviewer->id)
        ->where('status', DocumentWorkflowTaskStatus::Pending)
        ->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($reviewTask, $reviewer, (int) $company->id);

    $approveTask = DocumentWorkflowTask::query()
        ->where('assignee_user_id', $approver->id)
        ->where('status', DocumentWorkflowTaskStatus::Pending)
        ->firstOrFail();
    app(CompleteDocumentWorkflowTask::class)->handle($approveTask, $approver, (int) $company->id);

    $signRequest = DocumentRecipientRequest::query()
        ->where('document_instance_id', $instance->id)
        ->where('action', DocumentRecipientAction::Sign)
        ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
        ->firstOrFail();

    app(SubmitDocumentRecipientSignature::class)->handle(
        $signRequest,
        [
            'signed_name' => 'Subject Signer',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $instance->refresh();
    $lifecycle->refresh();
    $library = EmployeeDocument::query()->findOrFail($instance->employee_document_id);
    $current = DocumentInstanceVersion::query()->findOrFail($instance->current_version_id);

    expect($lifecycle->status)->toBe(DocumentLifecycleAutomationStatus::Completed)
        ->and($lifecycle->stage)->toBe(DocumentLifecycleAutomationStage::Done)
        ->and($current->version)->toBeGreaterThan(1)
        ->and($library->checksum)->toBe($current->checksum);

    $result = app(DocumentIntegrityAudit::class)->handle((int) $company->id, verifyFiles: true);

    expect($result->criticalCount())->toBe(0)
        ->and($result->highCount())->toBe(0);
});
