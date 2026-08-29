<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\Department;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentSigningPresetStep;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateSignaturePlacement;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use App\Support\Documents\Signing\Actions\RetryDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Signing\DocumentSigningFlowPresenter;
use App\Support\Documents\Signing\DocumentSigningManagementChainResolver;
use App\Support\Documents\Signing\DocumentSigningPresetPresenter;
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

function advancedFiveSignerPlacementConfig(): array
{
    return [
        'schema_version' => 2,
        'placements' => [
            [
                'id' => 'subject_signature',
                'type' => 'signature',
                'role' => 'subject',
                'slot_key' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.82,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'manager_signature',
                'type' => 'signature',
                'role' => 'manager',
                'slot_key' => 'manager_1',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.7,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'manager_signature_2',
                'type' => 'signature',
                'role' => 'manager',
                'slot_key' => 'manager_2',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.58,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'company_signatory_signature',
                'type' => 'signature',
                'role' => 'company_signatory',
                'slot_key' => 'company_signatory_1',
                'page' => 1,
                'x' => 0.55,
                'y' => 0.7,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
            [
                'id' => 'company_signatory_signature_2',
                'type' => 'signature',
                'role' => 'company_signatory',
                'slot_key' => 'company_signatory_2',
                'page' => 1,
                'x' => 0.55,
                'y' => 0.58,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ];
}

/**
 * @return array{manager1: User, manager2: Employee, parentDept: Department, childDept: Department}
 */
function attachTwoLevelManagementChain(Employee $subject, User $manager1User, User $manager2User): array
{
    $company = $subject->company;

    $manager1Employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $manager1User->id,
        'name' => $manager1User->name,
    ]);

    $manager2Employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $manager2User->id,
        'name' => $manager2User->name,
    ]);

    $parentDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS'.fake()->unique()->numerify('##'),
        'manager_id' => $manager2Employee->id,
        'status' => 'active',
    ]);

    $childDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew',
        'code' => 'CREW'.fake()->unique()->numerify('##'),
        'parent_id' => $parentDept->id,
        'manager_id' => $manager1Employee->id,
        'status' => 'active',
    ]);

    $subject->update(['department_id' => $childDept->id]);

    return [
        'manager1' => $manager1User,
        'manager2' => $manager2Employee,
        'parentDept' => $parentDept,
        'childDept' => $childDept,
    ];
}

function signRecipientRequest(DocumentRecipientRequest $request, ?User $actor, string $name): void
{
    app(SubmitDocumentRecipientSignature::class)->handle(
        $request,
        [
            'signed_name' => $name,
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
        $actor,
    );
}

test('advanced presets accept repeated managers and company signatories with labels', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $director = User::factory()->create(['status' => 'active']);
    $ceo = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($director, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($ceo, $company, 'documents.recipient-requests.respond');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Advanced chain',
        null,
        [
            ['recipient_role' => 'subject', 'step_label' => 'Employee'],
            ['recipient_role' => 'manager', 'step_label' => 'Department Manager'],
            ['recipient_role' => 'manager', 'step_label' => 'Parent Manager'],
            ['recipient_role' => 'company_signatory', 'step_label' => 'Director', 'target_user_id' => $director->id],
            ['recipient_role' => 'company_signatory', 'step_label' => 'CEO', 'target_user_id' => $ceo->id],
        ],
    );

    expect($preset->steps)->toHaveCount(5)
        ->and($preset->steps->pluck('step_label')->all())->toBe([
            'Employee',
            'Department Manager',
            'Parent Manager',
            'Director',
            'CEO',
        ]);
});

test('preset validation rejects invalid advanced step orders and duplicates', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $signer = User::factory()->create(['status' => 'active']);
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($signer, $company, 'documents.recipient-requests.respond');

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Manager first',
        null,
        [
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'subject'],
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Company then manager',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signer->id],
            ['recipient_role' => 'manager'],
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Duplicate company',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signer->id],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signer->id],
        ],
    ))->toThrow(ValidationException::class);

    $tooMany = [['recipient_role' => 'subject']];
    for ($i = 0; $i < 8; $i++) {
        $tooMany[] = ['recipient_role' => 'manager'];
    }

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Too many',
        null,
        $tooMany,
    ))->toThrow(ValidationException::class);
});

test('management chain resolver returns ordered unique actionable managers', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $m1 = User::factory()->create(['status' => 'active', 'name' => 'Mgr One']);
    $m2 = User::factory()->create(['status' => 'active', 'name' => 'Mgr Two']);
    giveCompanyPermission($m1, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($m2, $company, 'documents.recipient-requests.respond');
    attachTwoLevelManagementChain($fixtures['employee'], $m1, $m2);

    $resolved = app(DocumentSigningManagementChainResolver::class)
        ->resolveActionableUniqueManagers($fixtures['employee']->fresh(), $company->id);

    expect($resolved)->toHaveCount(2)
        ->and($resolved[0]['user']->id)->toBe($m1->id)
        ->and($resolved[1]['user']->id)->toBe($m2->id)
        ->and($resolved[0]['management_chain_position'])->toBe(1)
        ->and($resolved[1]['management_chain_position'])->toBe(2);
});

test('signature placement schema v2 validates contiguous slots and rejects sparse manager_2', function () {
    $valid = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig(
        advancedFiveSignerPlacementConfig(),
        1,
    );

    expect($valid['schema_version'])->toBe(2)
        ->and(DocumentSignaturePlacementValidator::validateSignatureForSlot(
            advancedFiveSignerPlacementConfig(),
            1,
            'manager_2',
        )['slot_key'])->toBe('manager_2');

    $sparse = advancedFiveSignerPlacementConfig();
    $sparse['placements'] = array_values(array_filter(
        $sparse['placements'],
        fn (array $p): bool => ($p['slot_key'] ?? '') !== 'manager_1',
    ));

    expect(fn () => DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($sparse, 1))
        ->toThrow(InvalidArgumentException::class);

    $v1 = defaultSignaturePlacementConfig();
    $v1['placements'][] = [
        'id' => 'manager_signature',
        'type' => 'signature',
        'role' => 'manager',
        'page' => 1,
        'x' => 0.1,
        'y' => 0.6,
        'width' => 0.25,
        'height' => 0.08,
        'required' => true,
    ];

    expect(DocumentSignaturePlacementValidator::validateSignatureForSlot($v1, 1, 'manager_1')['role'])
        ->toBe('manager');

    expect(fn () => DocumentSignaturePlacementValidator::validateSignatureForSlot($v1, 1, 'manager_2'))
        ->toThrow(InvalidArgumentException::class);
});

test('saving draft placement through editor normalizes schema v2', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $template = $fixtures['template'];
    $template->update(['template_format' => DocumentGenerationTemplateFormat::PdfOverlay]);
    $draft = DocumentGenerationTemplateVersion::factory()
        ->forTemplate($template)
        ->draft()
        ->create([
            'version' => 2,
            'source_pdf_page_count' => 1,
            'signature_placement_config' => defaultSignaturePlacementConfig(),
        ]);

    $saved = app(SaveDocumentGenerationTemplateSignaturePlacement::class)->handle(
        $draft,
        [
            'schema_version' => 2,
            'placements' => [
                [
                    'id' => 'subject_signature',
                    'type' => 'signature',
                    'role' => 'subject',
                    'slot_key' => 'subject',
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
                    'slot_key' => 'manager_1',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.6,
                    'width' => 0.25,
                    'height' => 0.08,
                    'required' => true,
                ],
            ],
        ],
    );

    expect($saved->signature_placement_config['schema_version'])->toBe(2)
        ->and($saved->signature_placement_config['placements'][1]['slot_key'])->toBe('manager_1');
});

test('five signer advanced flow produces immutable v1 through v6 chain', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    $m2 = User::factory()->create(['status' => 'active', 'name' => 'Parent Manager']);
    $director = User::factory()->create(['status' => 'active', 'name' => 'Director']);
    $ceo = User::factory()->create(['status' => 'active', 'name' => 'CEO']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    foreach ([$m1, $m2, $director, $ceo] as $user) {
        giveCompanyPermission($user, $company, 'documents.recipient-requests.respond');
    }
    attachTwoLevelManagementChain($fixtures['employee'], $m1, $m2);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Five stage',
        null,
        [
            ['recipient_role' => 'subject', 'step_label' => 'Employee'],
            ['recipient_role' => 'manager', 'step_label' => 'Department Manager'],
            ['recipient_role' => 'manager', 'step_label' => 'Parent Manager'],
            ['recipient_role' => 'company_signatory', 'step_label' => 'Director', 'target_user_id' => $director->id],
            ['recipient_role' => 'company_signatory', 'step_label' => 'CEO', 'target_user_id' => $ceo->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    $flow = $started['flow'];
    $snapshot = $flow->routing_definition_snapshot;

    expect($snapshot['schema_version'])->toBe(2)
        ->and(collect($snapshot['steps'])->pluck('signature_slot_key')->all())->toBe([
            'subject',
            'manager_1',
            'manager_2',
            'company_signatory_1',
            'company_signatory_2',
        ])
        ->and($started['request']->signature_slot_key)->toBe('subject')
        ->and($started['request']->signing_step_label_snapshot)->toBe('Employee');

    $v1Checksum = $fixtures['version']->checksum;

    signRecipientRequest($started['request'], null, 'Employee Name');
    $flow = $flow->fresh();
    expect($flow->current_step_sequence)->toBe(2);

    $manager1Request = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('signing_step_sequence', 2)
        ->firstOrFail();
    expect($manager1Request->signature_slot_key)->toBe('manager_1')
        ->and($manager1Request->recipient_user_id)->toBe($m1->id);
    signRecipientRequest($manager1Request, $m1, 'Dept Manager');

    $manager2Request = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('signing_step_sequence', 3)
        ->firstOrFail();
    expect($manager2Request->signature_slot_key)->toBe('manager_2')
        ->and($manager2Request->recipient_user_id)->toBe($m2->id);
    signRecipientRequest($manager2Request, $m2, 'Parent Manager');

    $directorRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('signing_step_sequence', 4)
        ->firstOrFail();
    expect($directorRequest->signature_slot_key)->toBe('company_signatory_1');
    signRecipientRequest($directorRequest, $director, 'Director');

    $ceoRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('signing_step_sequence', 5)
        ->firstOrFail();
    expect($ceoRequest->signature_slot_key)->toBe('company_signatory_2');
    signRecipientRequest($ceoRequest, $ceo, 'CEO');

    $flow = $flow->fresh();
    $fixtures['instance']->refresh();
    $fixtures['document']->refresh();
    $versions = DocumentInstanceVersion::query()
        ->where('document_instance_id', $fixtures['instance']->id)
        ->orderBy('version')
        ->get();

    expect($flow->status)->toBe(DocumentSigningFlowStatus::Completed)
        ->and($versions)->toHaveCount(6)
        ->and($versions->first()->checksum)->toBe($v1Checksum)
        ->and((int) $fixtures['instance']->current_version_id)->toBe($versions->last()->id)
        ->and($fixtures['document']->checksum)->toBe($versions->last()->checksum);

    $requests = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->orderBy('signing_step_sequence')
        ->get();

    expect($requests)->toHaveCount(5);
    for ($i = 1; $i < $requests->count(); $i++) {
        expect((int) $requests[$i]->source_document_instance_version_id)
            ->toBe((int) $requests[$i - 1]->result_document_instance_version_id);
    }

    $presented = app(DocumentSigningFlowPresenter::class)->forDocumentShow($flow);
    expect($presented['steps'][2]['step_label'])->toBe('Parent Manager')
        ->and($presented['steps'][3]['step_label'])->toBe('Director');
});

test('insufficient management hierarchy blocks flow start before creating flow', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active']);
    $director = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($m1, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($director, $company, 'documents.recipient-requests.respond');

    $managerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $m1->id,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Solo',
        'code' => 'SOLO'.fake()->unique()->numerify('##'),
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);
    $fixtures['employee']->update(['department_id' => $department->id]);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Needs two managers',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $director->id],
        ],
    );

    expect(fn () => app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    ))->toThrow(ValidationException::class);

    expect(DocumentSigningFlow::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(DocumentRecipientRequest::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('missing manager_2 placement blocks flow start', function () {
    $placement = advancedFiveSignerPlacementConfig();
    $placement['placements'] = array_values(array_filter(
        $placement['placements'],
        fn (array $p): bool => ($p['slot_key'] ?? '') !== 'manager_2',
    ));
    // Keep contiguous validation by also removing company slots for this unit — use only subject+manager_1
    $placement['placements'] = array_values(array_filter(
        $placement['placements'],
        fn (array $p): bool => in_array($p['slot_key'] ?? '', ['subject', 'manager_1'], true),
    ));

    $fixtures = makeRecipientFixturesWithSignaturePlacement($placement);
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active']);
    $m2 = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($m1, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($m2, $company, 'documents.recipient-requests.respond');
    attachTwoLevelManagementChain($fixtures['employee'], $m1, $m2);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Needs manager_2 slot',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'manager'],
        ],
    );

    expect(fn () => app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    ))->toThrow(ValidationException::class);

    expect(DocumentSigningFlow::query()->count())->toBe(0);
});

test('manager 2 permission loss blocks after manager 1 signature and retry reuses snapshot', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active']);
    $m2 = User::factory()->create(['status' => 'active']);
    $director = User::factory()->create(['status' => 'active']);
    $ceo = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    foreach ([$m1, $m2, $director, $ceo] as $user) {
        giveCompanyPermission($user, $company, 'documents.recipient-requests.respond');
    }
    attachTwoLevelManagementChain($fixtures['employee'], $m1, $m2);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Retry manager 2',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $director->id],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $ceo->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    signRecipientRequest($started['request'], null, 'Employee');
    $manager1Request = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $started['flow']->id)
        ->where('signing_step_sequence', 2)
        ->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $m2->revokePermissionTo('documents.recipient-requests.respond');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    signRecipientRequest($manager1Request, $m1, 'Manager 1');

    $flow = $started['flow']->fresh();
    expect($flow->status)->toBe(DocumentSigningFlowStatus::Blocked)
        ->and($fixtures['instance']->fresh()->versions()->count())->toBe(3)
        ->and(DocumentRecipientRequest::query()
            ->where('document_signing_flow_id', $flow->id)
            ->where('signing_step_sequence', 3)
            ->exists())->toBeFalse()
        ->and(app(DocumentSigningFlowPresenter::class)->forDocumentShow($flow)['can_retry'])->toBeTrue();

    giveCompanyPermission($m2, $company, 'documents.recipient-requests.respond');
    $retried = app(RetryDocumentSigningFlow::class)->handle($flow->fresh(), $hr, $company->id);

    expect($retried->status)->toBe(DocumentSigningFlowStatus::Active)
        ->and(DocumentRecipientRequest::query()
            ->where('document_signing_flow_id', $flow->id)
            ->where('signing_step_sequence', 3)
            ->where('recipient_user_id', $m2->id)
            ->count())->toBe(1);
});

test('schema v1 routing snapshot still advances with default slots', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement([
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
    ]);
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $managerUser = User::factory()->create(['status' => 'active']);
    $companySignatory = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($managerUser, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($companySignatory, $company, 'documents.recipient-requests.respond');

    $managerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $managerUser->id,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Legacy',
        'code' => 'LEG'.fake()->unique()->numerify('##'),
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);
    $fixtures['employee']->update(['department_id' => $department->id]);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Legacy compatible',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $companySignatory->id],
        ],
    );

    // Simulate an already-started Phase 6B-2B1 schema v1 flow.
    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_signing_preset_id' => $preset->id,
        'starting_document_instance_version_id' => $fixtures['version']->id,
        'preset_name_snapshot' => $preset->name,
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [
                [
                    'sequence' => 1,
                    'recipient_role' => 'subject',
                    'target_type' => 'subject_employee',
                    'employee_id' => $fixtures['employee']->id,
                    'recipient_user_id' => null,
                    'recipient_name' => $fixtures['employee']->name,
                ],
                [
                    'sequence' => 2,
                    'recipient_role' => 'manager',
                    'target_type' => 'department_manager',
                    'manager_employee_id' => $managerEmployee->id,
                    'recipient_user_id' => $managerUser->id,
                    'recipient_name' => $managerUser->name,
                ],
                [
                    'sequence' => 3,
                    'recipient_role' => 'company_signatory',
                    'target_type' => 'specific_user',
                    'recipient_user_id' => $companySignatory->id,
                    'recipient_name' => $companySignatory->name,
                ],
            ],
        ],
        'status' => DocumentSigningFlowStatus::Active,
        'current_step_sequence' => 1,
        'started_by' => $hr->id,
        'started_at' => now(),
    ]);

    $subjectRequest = DocumentRecipientRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $fixtures['instance']->id,
        'source_document_instance_version_id' => $fixtures['version']->id,
        'document_signing_flow_id' => $flow->id,
        'signing_step_sequence' => 1,
        'action' => DocumentRecipientAction::Sign,
        'recipient_type' => DocumentRecipientType::SubjectEmployee,
        'recipient_role' => DocumentRecipientRole::Subject,
        'employee_id' => $fixtures['employee']->id,
        'recipient_name_snapshot' => $fixtures['employee']->name,
        'status' => DocumentRecipientRequestStatus::AwaitingAction,
        'token_hash' => hash('sha256', 'legacy-token'),
        'expires_at' => now()->addDays(14),
        'requested_by' => $hr->id,
        'requested_at' => now(),
        'source_checksum_sha256' => $fixtures['version']->checksum,
    ]);

    signRecipientRequest($subjectRequest, null, 'Employee');
    $managerRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('signing_step_sequence', 2)
        ->firstOrFail();

    expect($managerRequest->recipient_user_id)->toBe($managerUser->id)
        ->and($managerRequest->recipient_role)->toBe(DocumentRecipientRole::Manager);

    signRecipientRequest($managerRequest, $managerUser, 'Manager');
    $companyRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->where('signing_step_sequence', 3)
        ->firstOrFail();
    signRecipientRequest($companyRequest, $companySignatory, 'Company');

    expect($flow->fresh()->status)->toBe(DocumentSigningFlowStatus::Completed)
        ->and($fixtures['instance']->fresh()->versions()->count())->toBe(4);
});

test('manual manager countersign cannot create a second manager stage', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active']);
    $m2 = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($m1, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($m2, $company, 'documents.recipient-requests.respond');
    attachTwoLevelManagementChain($fixtures['employee'], $m1, $m2);

    $created = app(CreateDocumentRecipientRequest::class)->handle(
        $fixtures['document'],
        DocumentRecipientAction::Sign,
        $hr,
        $company->id,
    );
    signRecipientRequest($created['request'], null, 'Employee');

    app(CreateDocumentManagerCountersignRequest::class)->handle(
        $fixtures['document']->fresh(),
        $hr,
        $company->id,
    );

    expect(fn () => app(CreateDocumentManagerCountersignRequest::class)->handle(
        $fixtures['document']->fresh(),
        $hr,
        $company->id,
    ))->toThrow(ValidationException::class);
});

test('existing null step labels on old preset rows remain presentable', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Null label preset',
        null,
        [['recipient_role' => 'subject']],
    );

    DocumentSigningPresetStep::query()
        ->where('document_signing_preset_id', $preset->id)
        ->update(['step_label' => null]);

    $presented = app(DocumentSigningPresetPresenter::class)
        ->detail($preset->fresh());

    expect($presented['steps'][0]['step_label'])->toBe('Employee');
});

test('advanced internal stages are unavailable on public document-action routes', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(advancedFiveSignerPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active']);
    $m2 = User::factory()->create(['status' => 'active']);
    $director = User::factory()->create(['status' => 'active']);
    $ceo = User::factory()->create(['status' => 'active']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    foreach ([$m1, $m2, $director, $ceo] as $user) {
        giveCompanyPermission($user, $company, 'documents.recipient-requests.respond');
    }
    attachTwoLevelManagementChain($fixtures['employee'], $m1, $m2);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Public guard',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $director->id],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $ceo->id],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );
    signRecipientRequest($started['request'], null, 'Employee');

    $managerRequest = DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $started['flow']->id)
        ->where('signing_step_sequence', 2)
        ->firstOrFail();

    expect($managerRequest->isPublicTokenRecipient())->toBeFalse()
        ->and($managerRequest->recipient_type)->toBe(DocumentRecipientType::CompanyUser);

    $this->get('/document-action/not-a-real-token')->assertNotFound();
});
