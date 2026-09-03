<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\Department;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestEvent;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\Documents\RecipientRequests\StampSignedDocumentInstancePdf;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function multiPlacementBox(
    string $id,
    string $slot,
    string $role,
    float $x = 0.1,
    int $page = 1,
    float $y = 0.7,
): array {
    return [
        'id' => $id,
        'type' => 'signature',
        'role' => $role,
        'slot_key' => $slot,
        'page' => $page,
        'x' => $x,
        'y' => $y,
        'width' => 0.25,
        'height' => 0.08,
        'required' => true,
    ];
}

function twoSubjectPlacementConfig(): array
{
    return [
        'schema_version' => 3,
        'placements' => [
            multiPlacementBox('employee_signature_en', 'subject', 'subject', 0.1, 1, 0.75),
            multiPlacementBox('employee_signature_ar', 'subject', 'subject', 0.5, 1, 0.75),
        ],
    ];
}

function managerMultiPlacementConfig(): array
{
    return [
        'schema_version' => 3,
        'placements' => [
            multiPlacementBox('subject_signature', 'subject', 'subject', 0.1, 1, 0.82),
            multiPlacementBox('manager_signature', 'manager_1', 'manager', 0.1, 1, 0.7),
            multiPlacementBox('manager_signature_copy', 'manager_1', 'manager', 0.5, 1, 0.7),
            multiPlacementBox('manager_signature_2', 'manager_2', 'manager', 0.1, 1, 0.55),
        ],
    ];
}

function twoPageMinimalPdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, 'Page 1');
    $pdf->AddPage();
    $pdf->Cell(0, 10, 'Page 2');

    return $pdf->Output('S');
}

function writeSignaturePngTemp(): string
{
    $path = sys_get_temp_dir().'/oms-sig-'.uniqid('', true).'.png';
    $uri = validSignatureDataUri();
    $raw = base64_decode((string) substr($uri, strpos($uri, ',') + 1), true);
    file_put_contents($path, $raw ?: '');

    return $path;
}

function attachMultiPlacementDepartmentManager(Employee $subject, User $managerUser): Employee
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
        'code' => 'CRW'.fake()->unique()->numerify('##'),
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);

    $subject->update(['department_id' => $department->id]);

    return $managerEmployee;
}

function attachMultiPlacementTwoLevelChain(Employee $subject, User $manager1User, User $manager2User): void
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
        'code' => 'CRW'.fake()->unique()->numerify('##'),
        'parent_id' => $parentDept->id,
        'manager_id' => $manager1Employee->id,
        'status' => 'active',
    ]);

    $subject->update(['department_id' => $childDept->id]);
}

test('resolver returns every physical placement for a v3 slot', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(managerMultiPlacementConfig());
    $resolver = app(ResolveDocumentSignaturePlacement::class);

    $subject = $resolver->forInstanceVersionSlotPlacements(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::Subject,
        'subject',
    );
    $manager1 = $resolver->forInstanceVersionSlotPlacements(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::Manager,
        'manager_1',
    );
    $manager2 = $resolver->forInstanceVersionSlotPlacements(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::Manager,
        'manager_2',
    );

    expect($subject)->toHaveCount(1)
        ->and(collect($manager1)->pluck('id')->all())->toBe(['manager_signature', 'manager_signature_copy'])
        ->and(collect($manager2)->pluck('id')->all())->toBe(['manager_signature_2']);
});

test('resolver returns two subject placements for a bilingual v3 template', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(twoSubjectPlacementConfig());

    $placements = app(ResolveDocumentSignaturePlacement::class)->forInstanceVersionSlotPlacements(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::Subject,
        'subject',
    );

    expect($placements)->toHaveCount(2)
        ->and(collect($placements)->pluck('id')->all())->toBe(['employee_signature_en', 'employee_signature_ar']);
});

test('stamper writes one pdf with the same signature on two boxes of one page', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(twoSubjectPlacementConfig());
    $sourceBytes = Storage::disk('local')->get($fixtures['version']->file_path);
    $png = writeSignaturePngTemp();

    $output = app(StampSignedDocumentInstancePdf::class)->handle(
        $fixtures['version'],
        $png,
        'PNG',
        twoSubjectPlacementConfig()['placements'],
    );

    expect($output)->toStartWith('%PDF')
        ->and(strlen($output))->toBeGreaterThan(strlen((string) $sourceBytes))
        ->and(Storage::disk('local')->get($fixtures['version']->file_path))->toBe($sourceBytes);

    @unlink($png);
});

test('stamper stamps the same signature across two pages without changing the source', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement([
        'schema_version' => 3,
        'placements' => [
            multiPlacementBox('employee_signature_en', 'subject', 'subject', 0.1, 1),
            multiPlacementBox('employee_signature_ar', 'subject', 'subject', 0.1, 2),
        ],
    ]);
    $twoPage = twoPageMinimalPdfBytes();
    Storage::disk('local')->put($fixtures['version']->file_path, $twoPage);
    $png = writeSignaturePngTemp();

    $output = app(StampSignedDocumentInstancePdf::class)->handle(
        $fixtures['version'],
        $png,
        'PNG',
        [
            multiPlacementBox('employee_signature_en', 'subject', 'subject', 0.1, 1),
            multiPlacementBox('employee_signature_ar', 'subject', 'subject', 0.1, 2),
        ],
    );

    $reader = new Fpdi;
    $tmp = sys_get_temp_dir().'/oms-stamped-'.uniqid('', true).'.pdf';
    file_put_contents($tmp, $output);
    $pages = $reader->setSourceFile($tmp);

    expect($pages)->toBe(2)
        ->and(Storage::disk('local')->get($fixtures['version']->file_path))->toBe($twoPage);

    @unlink($tmp);
    @unlink($png);
});

test('public employee signing stamps both subject placements once', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(twoSubjectPlacementConfig());
    $requester = User::factory()->create();
    $created = app(CreateDocumentRecipientRequest::class)->handle(
        $fixtures['document'],
        DocumentRecipientAction::Sign,
        $requester,
        $fixtures['company']->id,
    );

    $this->post(route('public.document-action.sign', ['token' => $created['raw_token']]), [
        'signed_name' => 'Employee Name',
        'signature_data' => validSignatureDataUri(),
        'consent' => '1',
    ])->assertRedirect(route('public.document-action.show', ['token' => $created['raw_token']]))
        ->assertSessionHasNoErrors();

    $request = $created['request']->fresh();
    $submitted = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('event', DocumentRecipientRequestEventType::SignatureSubmitted)
        ->get();
    $createdEvents = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('event', DocumentRecipientRequestEventType::SignedVersionCreated)
        ->get();

    expect($request->status)->toBe(DocumentRecipientRequestStatus::Completed)
        ->and($request->signature_image_path)->not->toBeEmpty()
        ->and($request->result_document_instance_version_id)->not->toBeNull()
        ->and(DocumentRecipientRequest::query()->where('document_instance_id', $fixtures['instance']->id)->count())->toBe(1)
        ->and($fixtures['instance']->fresh()->versions()->count())->toBe(2)
        ->and($submitted)->toHaveCount(1)
        ->and($createdEvents)->toHaveCount(1)
        ->and($submitted->first()->metadata['placement_count'])->toBe(2)
        ->and($submitted->first()->metadata['placement_ids'])->toBe([
            'employee_signature_en',
            'employee_signature_ar',
        ])
        ->and($createdEvents->first()->metadata['placement_count'])->toBe(2);
});

test('internal manager signing stamps both manager_1 placements and completes once', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(managerMultiPlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    giveCompanyPermission($managerUser, $fixtures['company'], 'documents.recipient-requests.respond');
    attachMultiPlacementDepartmentManager($fixtures['employee'], $managerUser);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $fixtures['company'], 'documents.recipient-requests.create');

    $subjectResult = app(CreateDocumentRecipientRequest::class)->handle(
        $fixtures['document'],
        DocumentRecipientAction::Sign,
        $requester,
        $fixtures['company']->id,
    );
    app(SubmitDocumentRecipientSignature::class)->handle(
        $subjectResult['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $fixtures['document']->fresh(),
        $requester,
        $fixtures['company']->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $managerRequest['request'],
        [
            'signed_name' => 'Dept Manager',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $managerUser,
    );

    $completed = $managerRequest['request']->fresh();
    $submitted = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $completed->id)
        ->where('event', DocumentRecipientRequestEventType::SignatureSubmitted)
        ->first();

    expect($completed->status)->toBe(DocumentRecipientRequestStatus::Completed)
        ->and($completed->signature_slot_key)->toBeNull()
        ->and($submitted?->metadata['signature_slot_key'])->toBe('manager_1')
        ->and($submitted?->metadata['placement_count'])->toBe(2)
        ->and($submitted?->metadata['placement_ids'])->toBe(['manager_signature', 'manager_signature_copy'])
        ->and(DocumentRecipientRequest::query()
            ->where('document_instance_id', $fixtures['instance']->id)
            ->where('recipient_role', DocumentRecipientRole::Manager)
            ->count())->toBe(1)
        ->and($fixtures['instance']->fresh()->versions()->count())->toBe(3);
});

test('two subject placements create one subject signing flow step', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(twoSubjectPlacementConfig());
    $hr = User::factory()->create();
    giveCompanyPermission($hr, $fixtures['company'], 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $fixtures['company'], 'documents.signing-presets.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $fixtures['company']->id,
        'Employee only',
        null,
        [['recipient_role' => 'subject']],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $fixtures['company']->id,
        $preset->id,
    );

    expect($started['request']->signature_slot_key)->toBe('subject')
        ->and(collect($started['flow']->routing_definition_snapshot['steps'])->pluck('signature_slot_key')->all())
        ->toBe(['subject']);

    app(SubmitDocumentRecipientSignature::class)->handle(
        $started['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    expect($started['flow']->fresh()->status)->toBe(DocumentSigningFlowStatus::Completed)
        ->and(DocumentRecipientRequest::query()->where('document_signing_flow_id', $started['flow']->id)->count())->toBe(1)
        ->and($fixtures['instance']->fresh()->versions()->count())->toBe(2);
});

test('two manager_1 placements plus manager_2 create two manager flow steps', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(managerMultiPlacementConfig());
    $company = $fixtures['company'];
    $hr = User::factory()->create();
    $m1 = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    $m2 = User::factory()->create(['status' => 'active', 'name' => 'Parent Manager']);

    giveCompanyPermission($hr, $company, 'documents.recipient-requests.create');
    giveCompanyPermission($hr, $company, 'documents.signing-presets.create');
    giveCompanyPermission($m1, $company, 'documents.recipient-requests.respond');
    giveCompanyPermission($m2, $company, 'documents.recipient-requests.respond');
    attachMultiPlacementTwoLevelChain($fixtures['employee'], $m1, $m2);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $hr,
        $company->id,
        'Two managers',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'manager'],
        ],
    );

    $started = app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $hr,
        $company->id,
        $preset->id,
    );

    expect(collect($started['flow']->routing_definition_snapshot['steps'])->pluck('signature_slot_key')->all())
        ->toBe(['subject', 'manager_1', 'manager_2']);
});
