<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\Department;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CancelDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\Actions\SupersedeStaleDocumentRecipientRequests;
use App\Support\Documents\Signing\Actions\AdvanceDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\BlockDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\CancelDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\RetryDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Signing\DocumentSigningFlowPresenter;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function tripleSigningPlacementConfig(): array
{
    return [
        'schema_version' => 1,
        'placements' => [
            [
                'id' => 'subject_signature',
                'type' => 'signature',
                'role' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'manager_signature',
                'type' => 'signature',
                'role' => 'manager',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.6,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'company_signatory_signature',
                'type' => 'signature',
                'role' => 'company_signatory',
                'page' => 1,
                'x' => 0.55,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ];
}

function attachFlowDepartmentManager(Employee $subject, User $managerUser): Employee
{
    $company = $subject->company;
    $managerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $managerUser->id,
        'name' => $managerUser->name,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew',
        'code' => 'CREW'.fake()->unique()->numerify('##'),
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);

    $subject->update(['department_id' => $department->id]);

    return $managerEmployee;
}

test('three party signing flow auto advances to completion', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    $companySignatory = User::factory()->create(['status' => 'active', 'name' => 'Company Signer']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($companySignatory, $company, 'documents.recipient-requests.respond');
    attachFlowDepartmentManager($fixtures['employee'], $managerUser);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Full auto',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $companySignatory->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    expect($started['flow']->status)->toBe(DocumentSigningFlowStatus::Active)
        ->and($started['request']->recipient_role)->toBe(DocumentRecipientRole::Subject)
        ->and($started['request']->signing_step_sequence)->toBe(1)
        ->and($started['raw_token'])->not->toBeEmpty();

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $flow = $started['flow']->fresh();
    $managerRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('recipient_role', DocumentRecipientRole::Manager)
        ->first();

    expect($flow->current_step_sequence)->toBe(2)
        ->and($managerRequest)->not->toBeNull()
        ->and($managerRequest->recipient_user_id)->toBe($managerUser->id);

    app(SubmitDocumentRecipientSignature::class)->handle(
        $managerRequest,
        [
            'signed_name' => 'Dept Manager',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $managerUser,
    );

    $flow->refresh();
    $companyRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('recipient_role', DocumentRecipientRole::CompanySignatory)
        ->first();

    expect($flow->current_step_sequence)->toBe(3)
        ->and($companyRequest)->not->toBeNull();

    app(SubmitDocumentRecipientSignature::class)->handle(
        $companyRequest,
        [
            'signed_name' => 'Company Signer',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $companySignatory,
    );

    $flow->refresh();
    $fixtures['instance']->refresh();

    expect($flow->status)->toBe(DocumentSigningFlowStatus::Completed)
        ->and($fixtures['instance']->versions()->count())->toBe(4)
        ->and($fixtures['document']->fresh()->checksum)->toBe(
            DocumentInstanceVersion::query()->findOrFail($fixtures['instance']->current_version_id)->checksum,
        );
});

test('manager permission loss after subject sign blocks flow without rolling back signature', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    $managerUser = User::factory()->create(['status' => 'active']);
    $companySignatory = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($companySignatory, $company, 'documents.recipient-requests.respond');
    attachFlowDepartmentManager($fixtures['employee'], $managerUser);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Blockable',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $companySignatory->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $managerUser->revokePermissionTo('documents.recipient-requests.respond');
    $managerUser->unsetRelation('roles');
    $managerUser->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $flow = $started['flow']->fresh();
    $started['request']->refresh();
    $fixtures['instance']->refresh();

    expect($started['request']->status)->toBe(DocumentRecipientRequestStatus::Completed)
        ->and($fixtures['instance']->versions()->count())->toBe(2)
        ->and($flow->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and(app(DocumentSigningFlowPresenter::class)
            ->forDocumentShow($flow)['can_retry'])->toBeTrue()
        ->and(DocumentRecipientRequest::query()
            ->where('document_signing_flow_id', $flow->id)
            ->where('recipient_role', DocumentRecipientRole::Manager)
            ->exists())->toBeFalse();

    // Failed retry must leave the flow Blocked (not falsely Active).
    expect(fn () => app(RetryDocumentSigningFlow::class)->handle($flow, $hr, $company->id))
        ->not->toThrow(Throwable::class);

    expect($flow->fresh()->status)->toBe(DocumentSigningFlowStatus::Blocked);

    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');

    $retried = app(RetryDocumentSigningFlow::class)->handle($flow->fresh(), $hr, $company->id);

    expect($retried->status)->toBe(DocumentSigningFlowStatus::Active)
        ->and(DocumentRecipientRequest::query()
            ->where('document_signing_flow_id', $flow->id)
            ->where('recipient_role', DocumentRecipientRole::Manager)
            ->where('recipient_user_id', $managerUser->id)
            ->count())->toBe(1);
});

test('open signing flow blocks manual manager countersign', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    $managerUser = User::factory()->create(['status' => 'active']);
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');
    attachFlowDepartmentManager($fixtures['employee'], $managerUser);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Subject manager',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
        ],
    );

    app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    expect(fn () => app(CreateDocumentManagerCountersignRequest::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
    ))->toThrow(ValidationException::class);
});

test('cancel active flow cancels awaiting request', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.cancel');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Cancelable',
        null,
        [['recipient_role' => 'subject']],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    $cancelled = app(CancelDocumentSigningFlow::class)->handle(
        $started['flow'],
        $hr,
        $company->id,
    );

    expect($cancelled->status)->toBe(DocumentSigningFlowStatus::Cancelled)
        ->and($started['request']->fresh()->status)->toBe(DocumentRecipientRequestStatus::Cancelled);
});

test('advance is idempotent for the next request', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    $signatory = User::factory()->create(['status' => 'active']);
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($signatory, $company, 'documents.recipient-requests.respond');

    // Need company placement too for two-party
    $fixtures = makeRecipientFixturesWithSignaturePlacement([
        'schema_version' => 1,
        'placements' => [
            ...defaultSignaturePlacementConfig()['placements'],
            [
                'id' => 'company_signatory_signature',
                'type' => 'signature',
                'role' => 'company_signatory',
                'page' => 1,
                'x' => 0.55,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ]);
    $company = $fixtures['company'];
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($signatory, $company, 'documents.recipient-requests.respond');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Two party',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signatory->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $completed = $started['request']->fresh();
    $flow = $started['flow']->fresh();

    app(AdvanceDocumentSigningFlow::class)->handle($flow, $completed);
    app(AdvanceDocumentSigningFlow::class)->handle($flow->fresh(), $completed);

    expect(DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('recipient_role', DocumentRecipientRole::CompanySignatory)
        ->count())->toBe(1);
});

test('manager hierarchy change after start does not change snapshot recipient', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Original Manager']);
    $otherManager = User::factory()->create(['status' => 'active', 'name' => 'Replacement Manager']);
    $companySignatory = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($otherManager, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($companySignatory, $company, 'documents.recipient-requests.respond');
    $managerEmployee = attachFlowDepartmentManager($fixtures['employee'], $managerUser);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Snapshot',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $companySignatory->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    $replacement = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $otherManager->id,
    ]);
    $deptId = $fixtures['employee']->fresh()->department_id;
    Department::query()->whereKey($deptId)->update(['manager_id' => $replacement->id]);

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $managerRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $started['flow']->id)
        ->where('recipient_role', DocumentRecipientRole::Manager)
        ->first();

    expect($managerRequest?->recipient_user_id)->toBe($managerUser->id)
        ->and($managerRequest?->recipient_user_id)->not->toBe($otherManager->id);
});

test('cancelling a flow-linked recipient request cancels the flow via flow-first lock order', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.cancel');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Cancel via request',
        null,
        [['recipient_role' => 'subject']],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    $cancelledRequest = app(CancelDocumentRecipientRequest::class)->handle(
        $started['request'],
        $hr,
        $company->id,
    );

    expect($cancelledRequest->status)->toBe(DocumentRecipientRequestStatus::Cancelled)
        ->and($started['flow']->fresh()->status)->toBe(DocumentSigningFlowStatus::Cancelled);
});

test('standalone recipient cancellation still works outside a signing flow', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];

    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.cancel');

    $created = app(CreateDocumentRecipientRequest::class)->handle(
        $fixtures['document'],
        DocumentRecipientAction::Sign,
        $hr,
        $company->id,
    );

    $cancelled = app(CancelDocumentRecipientRequest::class)->handle(
        $created['request'],
        $hr,
        $company->id,
    );

    expect($cancelled->status)->toBe(DocumentRecipientRequestStatus::Cancelled)
        ->and($cancelled->document_signing_flow_id)->toBeNull();
});

test('blocking completed or cancelled flows does not resurrect them', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Subject only complete',
        null,
        [['recipient_role' => 'subject']],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $completedFlow = $started['flow']->fresh();
    expect($completedFlow->status)->toBe(DocumentSigningFlowStatus::Completed);

    $afterCompleted = app(BlockDocumentSigningFlow::class)->handle(
        $completedFlow,
        $company->id,
        'Should not apply',
    );
    expect($afterCompleted->status)->toBe(DocumentSigningFlowStatus::Completed)
        ->and($afterCompleted->blocked_reason)->toBeNull();

    $fixturesB = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $companyB = $fixturesB['company'];
    $hrB = User::factory()->create();
    giveCompanyPermission($hrB, $companyB, 'documents.recipient-requests.create');
    giveCompanyPermission($hrB, $companyB, 'documents.recipient-requests.cancel');
    giveCompanyPermission($hrB, $companyB, 'documents.signing-presets.create');

    $presetB = app(StoreDocumentSigningPreset::class)->handle(
        $hrB,
        $companyB->id,
        'Cancel then block',
        null,
        [['recipient_role' => 'subject']],
    );

    $startedB = app(StartDocumentSigningFlow::class)->handle(
        $fixturesB['document'],
        $hrB,
        $companyB->id,
        $presetB->id,
    );

    app(CancelDocumentSigningFlow::class)->handle($startedB['flow'], $hrB, $companyB->id);

    $afterCancelled = app(BlockDocumentSigningFlow::class)->handle(
        $startedB['flow']->fresh(),
        $companyB->id,
        'Should not apply',
    );

    expect($afterCancelled->status)->toBe(DocumentSigningFlowStatus::Cancelled)
        ->and($afterCancelled->blocked_reason)->toBeNull();
});

test('repeated block with the same reason is idempotent', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSigningPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $managerUser = User::factory()->create(['status' => 'active']);
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');
    attachFlowDepartmentManager($fixtures['employee'], $managerUser);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Idempotent block',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $managerUser->revokePermissionTo('documents.recipient-requests.respond');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $flow = $started['flow']->fresh();
    $reason = $flow->blocked_reason;

    $first = app(BlockDocumentSigningFlow::class)->handle($flow, $company->id, (string) $reason);
    $second = app(BlockDocumentSigningFlow::class)->handle($first, $company->id, (string) $reason);

    expect($first->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and($second->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and($second->blocked_reason)->toBe($reason);
});

test('superseded current-step flow is blocked and not retryable', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Supersede step',
        null,
        [['recipient_role' => 'subject']],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    advanceDocumentInstanceCurrentVersion(
        $fixtures['instance'],
        "document-instances/{$company->id}/flow-superseding.pdf",
    );

    app(SupersedeStaleDocumentRecipientRequests::class)->forInstanceVersionChange(
        $fixtures['instance']->fresh(),
        $company->id,
    );

    $flow = $started['flow']->fresh();
    $presented = app(DocumentSigningFlowPresenter::class)->forDocumentShow($flow);

    expect($started['request']->fresh()->status)->toBe(DocumentRecipientRequestStatus::Superseded)
        ->and($flow->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and($presented['can_retry'])->toBeFalse()
        ->and($presented['can_cancel'])->toBeTrue();
});

test('expired current-step flow is blocked and not retryable', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Expire step',
        null,
        [['recipient_role' => 'subject']],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    $started['request']->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request']->fresh(),
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ValidationException::class);

    $flow = $started['flow']->fresh();
    $presented = app(DocumentSigningFlowPresenter::class)->forDocumentShow($flow);

    expect($started['request']->fresh()->status)->toBe(DocumentRecipientRequestStatus::Expired)
        ->and($flow->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and($presented['can_retry'])->toBeFalse()
        ->and($presented['can_cancel'])->toBeTrue();
});
