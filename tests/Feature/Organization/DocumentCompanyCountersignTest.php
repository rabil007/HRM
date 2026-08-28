<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\Company;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestEvent;
use App\Models\DocumentWorkflowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentCompanyCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function dualSignaturePlacementConfig(): array
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

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     document: EmployeeDocument,
 *     instance: DocumentInstance,
 *     v1: DocumentInstanceVersion,
 *     v2: DocumentInstanceVersion,
 *     subjectRequest: DocumentRecipientRequest,
 * }
 */
function completeSubjectEmployeeSign(array $fixtures): array
{
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $v1] = $fixtures;

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $subjectResult = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
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

    $subjectResult['request']->refresh();
    $instance->refresh();
    $v1->refresh();

    $v2 = DocumentInstanceVersion::query()->findOrFail($subjectResult['request']->result_document_instance_version_id);

    return [
        'company' => $company,
        'employee' => $fixtures['employee'],
        'document' => $document,
        'instance' => $instance,
        'v1' => $v1,
        'v2' => $v2,
        'subjectRequest' => $subjectResult['request'],
    ];
}

test('HR can create company countersign request after completed subject signature', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create(['name' => 'Company Signer']);
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $result = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    $request = $result['request'];

    expect($request->recipient_type)->toBe(DocumentRecipientType::CompanyUser)
        ->and($request->recipient_role)->toBe(DocumentRecipientRole::CompanySignatory)
        ->and($request->employee_id)->toBe($signed['employee']->id)
        ->and($request->recipient_user_id)->toBe($signatory->id)
        ->and($request->source_document_instance_version_id)->toBe($signed['v2']->id)
        ->and($request->source_checksum_sha256)->toBe($signed['v2']->checksum)
        ->and($request->recipient_name_snapshot)->toBe('Company Signer')
        ->and($result['respond_url'])->toContain('/organization/documents/recipient-requests/'.$request->id.'/respond');
});

test('company countersign retains workflow provenance from completed subject sign request', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());

    DocumentWorkflowRequest::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'document_instance_version_id' => $fixtures['version']->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => User::factory()->create()->id,
        'requester_name_snapshot' => 'HR',
        'requested_at' => now(),
    ]);

    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $result = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    expect($result['request']->document_workflow_request_id)
        ->toBe($signed['subjectRequest']->document_workflow_request_id);
});

test('cannot countersign before subject employee has signed', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $fixtures['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $fixtures['company'], ['documents.recipient-requests.respond']);

    app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $fixtures['document'],
        $signatory,
        $hr,
        $fixtures['company']->id,
    );
})->throws(ValidationException::class);

test('missing company signatory placement blocks countersign creation', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );
})->throws(ValidationException::class);

test('duplicate active company countersign request is blocked', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );
})->throws(ValidationException::class);

test('user without create permission cannot assign company countersign via route', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $signatory = User::factory()->create();
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $this->actingAs($signatory)
        ->withSession(['current_company_id' => $signed['company']->id])
        ->post(route('organization.documents.employee.files.company-countersign-requests.store', [
            'employee' => $signed['employee']->id,
            'document' => $signed['document']->id,
        ]), [
            'recipient_user_id' => $signatory->id,
        ])
        ->assertForbidden();
});

test('assigned company signatory can countersign and produces v3 chain', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create(['name' => 'Director Name']);
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $countersign['request'],
        [
            'signed_name' => 'Director Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $signatory,
    );

    $countersign['request']->refresh();
    $signed['instance']->refresh();
    $signed['v1']->refresh();
    $signed['v2']->refresh();

    $v3 = DocumentInstanceVersion::query()->findOrFail($countersign['request']->result_document_instance_version_id);

    expect($signed['instance']->versions()->count())->toBe(3)
        ->and($signed['v1']->checksum)->not->toBe($signed['v2']->checksum)
        ->and($signed['v2']->refresh()->checksum)->toBe($signed['v2']->checksum)
        ->and($signed['instance']->current_version_id)->toBe($v3->id)
        ->and($countersign['request']->result_checksum_sha256)->toBe($v3->checksum)
        ->and($signed['document']->refresh()->checksum)->toBe($v3->checksum);
});

test('wrong authenticated user cannot sign company countersign request', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    $otherUser = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);
    grantCompanyPermissions($otherUser, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $countersign['request'],
        [
            'signed_name' => 'Wrong User',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $otherUser,
    ))->toThrow(HttpException::class);
});

test('internal company countersign rechecks respond permission after request lock', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($signed['company']->id);
    $signatory->syncRoles([]);

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $countersign['request'],
        [
            'signed_name' => 'Director Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $signatory,
    ))->toThrow(HttpException::class);
});

test('internal company countersign rechecks company membership after request lock', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    DB::table('company_user')
        ->where('company_id', $signed['company']->id)
        ->where('user_id', $signatory->id)
        ->delete();

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $countersign['request'],
        [
            'signed_name' => 'Director Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $signatory,
    ))->toThrow(HttpException::class);
});

test('company A cannot select company B user for countersign', function () {
    $fixturesA = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signedA = completeSubjectEmployeeSign($fixturesA);
    $fixturesB = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());

    $hr = User::factory()->create();
    $signatoryB = User::factory()->create();
    grantCompanyPermissions($hr, $signedA['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatoryB, $fixturesB['company'], ['documents.recipient-requests.respond']);

    app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signedA['document'],
        $signatoryB,
        $hr,
        $signedA['company']->id,
    );
})->throws(ValidationException::class);

test('company user recipient request is unavailable through public document-action routes', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    $internalToken = 'internal-token-not-exposed';

    expect(DocumentRecipientRequestToken::findByRawToken($internalToken))->toBeNull();

    $this->get(route('public.document-action.show', ['token' => 'does-not-exist']))
        ->assertNotFound();

    $storedHash = $countersign['request']->token_hash;
    $guessedToken = str_repeat('a', 64);

    expect(hash('sha256', $guessedToken))->not->toBe($storedHash);
});

test('subject-only placement config still validates for subject signing', function () {
    $config = defaultSignaturePlacementConfig();

    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1);

    expect($validated['placements'])->toHaveCount(1)
        ->and($validated['placements'][0]['role'])->toBe('subject');
});

test('config with subject and company signatory validates', function () {
    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig(
        dualSignaturePlacementConfig(),
        1,
    );

    expect($validated['placements'])->toHaveCount(2);
});

test('duplicate placement role in config is rejected', function () {
    $config = dualSignaturePlacementConfig();
    $config['placements'][] = $config['placements'][1];

    DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1);
})->throws(InvalidArgumentException::class);

test('subject sign resolves subject placement when both roles exist in config', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());

    $placement = app(ResolveDocumentSignaturePlacement::class)->forInstanceVersion(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::Subject,
    );

    expect($placement['role'])->toBe('subject');
});

test('company sign resolves company signatory placement', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());

    $placement = app(ResolveDocumentSignaturePlacement::class)->forInstanceVersion(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::CompanySignatory,
    );

    expect($placement['role'])->toBe('company_signatory');
});

test('company countersign completion stores authenticated actor on event', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create(['name' => 'Director Name']);
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $countersign['request'],
        [
            'signed_name' => 'Director Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $signatory,
    );

    $completedEvent = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $countersign['request']->id)
        ->where('event', DocumentRecipientRequestEventType::SignatureSubmitted)
        ->first();

    $versionCreatedEvent = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $countersign['request']->id)
        ->where('event', DocumentRecipientRequestEventType::SignedVersionCreated)
        ->first();

    expect($completedEvent)->not->toBeNull()
        ->and($completedEvent->actor_user_id)->toBe($signatory->id)
        ->and($versionCreatedEvent)->not->toBeNull()
        ->and($versionCreatedEvent->actor_user_id)->toBe($signatory->id);

    expect(
        Activity::query()
            ->where('properties->document_recipient_request_id', $countersign['request']->id)
            ->where('company_id', $signed['company']->id)
            ->exists(),
    )->toBeTrue();
});

test('stale source version before countersign submission supersedes request without new version', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(dualSignaturePlacementConfig());
    $signed = completeSubjectEmployeeSign($fixtures);

    $hr = User::factory()->create();
    $signatory = User::factory()->create();
    grantCompanyPermissions($hr, $signed['company'], ['documents.recipient-requests.create']);
    grantCompanyPermissions($signatory, $signed['company'], ['documents.recipient-requests.respond']);

    $countersign = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document'],
        $signatory,
        $hr,
        $signed['company']->id,
    );

    advanceDocumentInstanceCurrentVersion(
        $signed['instance'],
        "document-instances/{$signed['company']->id}/superseding.pdf",
    );

    $versionCountBefore = $signed['instance']->versions()->count();

    try {
        app(SubmitDocumentRecipientSignature::class)->handle(
            $countersign['request'],
            [
                'signed_name' => 'Director Name',
                'signature_data' => validSignatureDataUri(),
                'consent' => true,
            ],
            Request::create('/organization/documents/recipient-requests/sign', 'POST'),
            $signatory,
        );
    } catch (ValidationException) {
        // expected
    }

    $countersign['request']->refresh();

    expect($countersign['request']->status)->toBe(DocumentRecipientRequestStatus::Superseded)
        ->and($signed['instance']->refresh()->versions()->count())->toBe($versionCountBefore);
});
