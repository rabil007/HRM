<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestEvent;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentCompanyCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentRecipientManagerResolver;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function grantManagerRespond(User $user, Company $company): void
{
    giveCompanyPermission($user, $company, 'documents.recipient-requests.respond');
}

function grantManagerCreate(User $user, Company $company): void
{
    giveCompanyPermission($user, $company, 'documents.recipient-requests.create');
}

function tripleSignaturePlacementConfig(): array
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

/**
 * @return array{managerEmployee: Employee, managerUser: User, department: Department}
 */
function attachEligibleDepartmentManager(Employee $subject, User $managerUser, string $deptName = 'Crew'): array
{
    $company = $subject->company;
    $managerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $managerUser->id,
        'name' => $managerUser->name,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => $deptName,
        'code' => strtoupper(substr($deptName, 0, 4)).fake()->unique()->numerify('##'),
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);

    $subject->update(['department_id' => $department->id]);

    return [
        'managerEmployee' => $managerEmployee,
        'managerUser' => $managerUser,
        'department' => $department,
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
function completeSubjectEmployeeSignForManager(array $fixtures): array
{
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $v1] = $fixtures;

    $requester = User::factory()->create();
    grantManagerCreate($requester, $company);

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

test('direct department manager resolves for countersigning', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Direct Manager']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);

    $resolved = app(DocumentRecipientManagerResolver::class)->resolveForEmployee(
        $fixtures['employee']->fresh(),
        $fixtures['company']->id,
    );

    expect($resolved['user']->id)->toBe($managerUser->id);
});

test('parent management-chain manager resolves when direct manager is not actionable', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $company = $fixtures['company'];

    $parentManagerUser = User::factory()->create(['status' => 'active', 'name' => 'Parent Manager']);
    grantManagerRespond($parentManagerUser, $company);
    $parentManagerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $parentManagerUser->id,
    ]);

    $inactiveDirectUser = User::factory()->create(['status' => 'active', 'name' => 'Inactive Direct']);
    $inactiveDirectEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'inactive',
        'user_id' => $inactiveDirectUser->id,
    ]);
    grantManagerRespond($inactiveDirectUser, $company);

    $parentDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Ops',
        'code' => 'OPS'.fake()->unique()->numerify('##'),
        'manager_id' => $parentManagerEmployee->id,
        'status' => 'active',
    ]);

    $childDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew',
        'code' => 'CREW'.fake()->unique()->numerify('##'),
        'parent_id' => $parentDept->id,
        'manager_id' => $inactiveDirectEmployee->id,
        'status' => 'active',
    ]);

    $fixtures['employee']->update(['department_id' => $childDept->id]);

    $resolved = app(DocumentRecipientManagerResolver::class)->resolveForEmployee(
        $fixtures['employee']->fresh(),
        $company->id,
    );

    expect($resolved['user']->id)->toBe($parentManagerUser->id);
});

test('manager without respond permission is rejected by resolver', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    // membership only — no respond permission
    grantCompanyPermissions($managerUser, $fixtures['company'], ['documents.recipient-requests.view']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);

    expect(fn () => app(DocumentRecipientManagerResolver::class)->resolveForEmployee(
        $fixtures['employee']->fresh(),
        $fixtures['company']->id,
    ))->toThrow(ValidationException::class);
});

test('HR can create manager countersign request after subject signature', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);

    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    $result = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
    );

    $request = $result['request'];

    expect($request->recipient_type)->toBe(DocumentRecipientType::CompanyUser)
        ->and($request->recipient_role)->toBe(DocumentRecipientRole::Manager)
        ->and($request->employee_id)->toBe($signed['employee']->id)
        ->and($request->recipient_user_id)->toBe($managerUser->id)
        ->and($request->source_document_instance_version_id)->toBe($signed['v2']->id)
        ->and($request->source_checksum_sha256)->toBe($signed['v2']->checksum)
        ->and($request->recipient_name_snapshot)->toBe('Dept Manager');
});

test('manager request before subject signature is blocked', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $fixtures['company']);

    expect(fn () => app(CreateDocumentManagerCountersignRequest::class)->handle(
        $fixtures['document'],
        $hr,
        $fixtures['company']->id,
    ))->toThrow(ValidationException::class);
});

test('missing manager placement blocks manager countersign creation', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(defaultSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    expect(fn () => app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
    ))->toThrow(ValidationException::class);
});

test('assigned manager can sign producing immutable v3 and company can follow to v4', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);

    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    $companySignatory = User::factory()->create(['status' => 'active', 'name' => 'Company Signer']);
    grantManagerCreate($hr, $signed['company']);
    grantManagerRespond($companySignatory, $signed['company']);

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
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

    $managerRequest['request']->refresh();
    $signed['instance']->refresh();
    $v3 = DocumentInstanceVersion::query()->findOrFail($managerRequest['request']->result_document_instance_version_id);

    expect($signed['instance']->versions()->count())->toBe(3)
        ->and($signed['instance']->current_version_id)->toBe($v3->id)
        ->and($managerRequest['request']->recipient_role)->toBe(DocumentRecipientRole::Manager);

    $companyRequest = app(CreateDocumentCompanyCountersignRequest::class)->handle(
        $signed['document']->fresh(),
        $companySignatory,
        $hr,
        $signed['company']->id,
    );

    expect($companyRequest['request']->source_document_instance_version_id)->toBe($v3->id);

    app(SubmitDocumentRecipientSignature::class)->handle(
        $companyRequest['request'],
        [
            'signed_name' => 'Company Signer',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $companySignatory,
    );

    $companyRequest['request']->refresh();
    $signed['instance']->refresh();
    $signed['v1']->refresh();
    $signed['v2']->refresh();
    $v3->refresh();
    $v4 = DocumentInstanceVersion::query()->findOrFail($companyRequest['request']->result_document_instance_version_id);

    expect($signed['instance']->versions()->count())->toBe(4)
        ->and($signed['instance']->current_version_id)->toBe($v4->id)
        ->and($signed['v1']->checksum)->not->toBe($signed['v2']->checksum)
        ->and($signed['v2']->checksum)->not->toBe($v3->checksum)
        ->and($v3->checksum)->not->toBe($v4->checksum)
        ->and($signed['document']->fresh()->checksum)->toBe($v4->checksum);
});

test('wrong authenticated user cannot sign manager request', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    $otherUser = User::factory()->create(['status' => 'active']);
    grantManagerCreate($hr, $signed['company']);
    grantManagerRespond($otherUser, $signed['company']);

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
    );

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $managerRequest['request'],
        [
            'signed_name' => 'Wrong User',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $otherUser,
    ))->toThrow(HttpException::class);
});

test('manager request is unavailable through public document-action routes', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
    );

    $this->get(route('public.document-action.show', ['token' => 'does-not-exist']))
        ->assertNotFound();

    expect($managerRequest['request']->isPublicTokenRecipient())->toBeFalse()
        ->and($managerRequest['request']->isInternalSigner())->toBeTrue()
        ->and($managerRequest['request']->isInternalManager())->toBeTrue();
});

test('subject manager and company signatory placements validate together', function () {
    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig(
        tripleSignaturePlacementConfig(),
        1,
    );

    expect($validated['placements'])->toHaveCount(3);
});

test('manager placement is resolved independently when all roles exist', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());

    $placement = app(ResolveDocumentSignaturePlacement::class)->forInstanceVersion(
        $fixtures['instance'],
        $fixtures['version'],
        DocumentRecipientRole::Manager,
    );

    expect($placement['role'])->toBe('manager');
});

test('manager signing records authenticated actor on signature events', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active', 'name' => 'Dept Manager']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
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

    $submitted = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $managerRequest['request']->id)
        ->where('event', DocumentRecipientRequestEventType::SignatureSubmitted)
        ->first();

    $versionCreated = DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $managerRequest['request']->id)
        ->where('event', DocumentRecipientRequestEventType::SignedVersionCreated)
        ->first();

    expect($submitted?->actor_user_id)->toBe($managerUser->id)
        ->and($versionCreated?->actor_user_id)->toBe($managerUser->id);
});

test('stale source before manager submit supersedes without new version', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
    );

    advanceDocumentInstanceCurrentVersion(
        $signed['instance'],
        "document-instances/{$signed['company']->id}/manager-superseding.pdf",
    );

    $versionCountBefore = $signed['instance']->versions()->count();

    try {
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
    } catch (ValidationException) {
        // expected
    }

    $managerRequest['request']->refresh();

    expect($managerRequest['request']->status)->toBe(DocumentRecipientRequestStatus::Superseded)
        ->and($signed['instance']->refresh()->versions()->count())->toBe($versionCountBefore);
});

test('manager countersign rechecks respond permission after request lock', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    $managerRequest = app(CreateDocumentManagerCountersignRequest::class)->handle(
        $signed['document'],
        $hr,
        $signed['company']->id,
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($signed['company']->id);
    $managerUser->revokePermissionTo('documents.recipient-requests.respond');
    $managerUser->unsetRelation('roles');
    $managerUser->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $managerRequest['request'],
        [
            'signed_name' => 'Dept Manager',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/organization/documents/recipient-requests/sign', 'POST'),
        $managerUser,
    ))->toThrow(HttpException::class);
});

test('manager create route does not accept client-supplied recipient_user_id', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement(tripleSignaturePlacementConfig());
    $managerUser = User::factory()->create(['status' => 'active']);
    $imposter = User::factory()->create(['status' => 'active']);
    grantManagerRespond($managerUser, $fixtures['company']);
    grantManagerRespond($imposter, $fixtures['company']);
    attachEligibleDepartmentManager($fixtures['employee'], $managerUser);
    $signed = completeSubjectEmployeeSignForManager($fixtures);

    $hr = User::factory()->create();
    grantManagerCreate($hr, $signed['company']);

    $this->actingAs($hr)
        ->withSession(['current_company_id' => $signed['company']->id])
        ->post(route('organization.documents.employee.files.manager-countersign-requests.store', [
            'employee' => $signed['employee']->id,
            'document' => $signed['document']->id,
        ]), [
            'recipient_user_id' => $imposter->id,
        ])
        ->assertRedirect();

    $request = DocumentRecipientRequest::query()
        ->where('recipient_role', DocumentRecipientRole::Manager)
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->recipient_user_id)->toBe($managerUser->id)
        ->and($request->recipient_user_id)->not->toBe($imposter->id);
});
