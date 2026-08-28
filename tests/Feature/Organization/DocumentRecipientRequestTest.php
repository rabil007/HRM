<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestEvent;
use App\Models\DocumentWorkflowRequest;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientAcknowledgement;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use App\Support\Documents\RecipientRequests\DocumentRecipientSigningTransactionProbe;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
    DocumentRecipientSigningTransactionProbe::reset();
});

test('stores token hash not raw token when creating recipient request', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    $stored = DocumentRecipientRequest::query()->first();

    expect($stored)->not->toBeNull()
        ->and($stored->token_hash)->toBe(hash('sha256', $result['raw_token']))
        ->and($stored->token_hash)->not->toBe($result['raw_token']);

    expect(
        Activity::query()
            ->where('properties->document_recipient_request_id', $stored->id)
            ->where('properties->token', '!=', null)
            ->exists(),
    )->toBeFalse();
});

test('valid raw token resolves recipient request', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    $resolved = DocumentRecipientRequestToken::findByRawToken($result['raw_token']);

    expect($resolved)->not->toBeNull()
        ->and($resolved?->id)->toBe($result['request']->id);
});

test('invalid token returns not found on public show route', function () {
    $this->get(route('public.document-action.show', ['token' => 'invalid-token']))
        ->assertNotFound();
});

test('blocks duplicate active recipient requests for same version and action', function () {
    ['company' => $company, 'document' => $document] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );
})->throws(ValidationException::class);

test('approved workflow allows recipient request creation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => User::factory()->create()->id,
        'requester_name_snapshot' => 'HR',
        'requested_at' => now(),
    ]);

    $requester = User::factory()->create();
    grantCompanyPermissions($requester, $company, ['documents.recipient-requests.create']);

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    expect($result['request']->status)->toBe(DocumentRecipientRequestStatus::AwaitingAction);
});

test('pending workflow blocks recipient request creation', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => User::factory()->create()->id,
        'requester_name_snapshot' => 'HR',
        'requested_at' => now(),
    ]);

    $requester = User::factory()->create();

    app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );
})->throws(ValidationException::class);

test('acknowledgement stores evidence without creating new document version', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $create = app(CreateDocumentRecipientRequest::class);
    $result = $create->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    $recipientRequest = $result['request'];
    $versionCountBefore = $instance->versions()->count();

    app(SubmitDocumentRecipientAcknowledgement::class)->handle(
        $recipientRequest,
        ['name' => 'Employee Name', 'acknowledgement' => true],
        Request::create('/document-action/test', 'POST'),
    );

    $recipientRequest->refresh();
    $instance->refresh();

    expect($recipientRequest->status)->toBe(DocumentRecipientRequestStatus::Completed)
        ->and($recipientRequest->acknowledgement_text_snapshot)->not->toBeNull()
        ->and($recipientRequest->result_document_instance_version_id)->toBeNull()
        ->and($instance->current_version_id)->toBe($version->id)
        ->and($instance->versions()->count())->toBe($versionCountBefore);
});

test('signing creates new immutable signed version and updates current version', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $sourceChecksum = $version->checksum;

    $requester = User::factory()->create();
    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $result['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $result['request']->refresh();
    $instance->refresh();
    $version->refresh();

    expect($result['request']->status)->toBe(DocumentRecipientRequestStatus::Completed)
        ->and($result['request']->result_document_instance_version_id)->not->toBeNull()
        ->and($instance->current_version_id)->toBe($result['request']->result_document_instance_version_id)
        ->and($version->checksum)->toBe($sourceChecksum)
        ->and($instance->versions()->count())->toBe(2);
});

function countRecipientEvents(DocumentRecipientRequest $request, DocumentRecipientRequestEventType $event): int
{
    return DocumentRecipientRequestEvent::query()
        ->where('document_recipient_request_id', $request->id)
        ->where('event', $event)
        ->count();
}

test('stale sign submission persists superseded status and rejects safely', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $requester = User::factory()->create();
    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    $recipientRequest = $result['request'];
    $versionCountBefore = $instance->versions()->count();
    $currentVersionIdBeforeStaleSubmit = $instance->current_version_id;

    advanceDocumentInstanceCurrentVersion(
        $instance,
        "document-instances/{$company->id}/updated-v2.pdf",
    );

    $instance->refresh();

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $recipientRequest,
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ValidationException::class, 'updated');

    $recipientRequest->refresh();
    $instance->refresh();

    expect($recipientRequest->status)->toBe(DocumentRecipientRequestStatus::Superseded)
        ->and($recipientRequest->result_document_instance_version_id)->toBeNull()
        ->and(countRecipientEvents($recipientRequest, DocumentRecipientRequestEventType::RequestSuperseded))->toBe(1)
        ->and($instance->versions()->count())->toBe($versionCountBefore + 1)
        ->and($instance->current_version_id)->not->toBe($version->id)
        ->and($instance->current_version_id)->not->toBe($currentVersionIdBeforeStaleSubmit);
});

test('stale acknowledgement submission persists superseded status and rejects safely', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    $recipientRequest = $result['request'];
    $versionCountBefore = $instance->versions()->count();

    advanceDocumentInstanceCurrentVersion(
        $instance,
        "document-instances/{$company->id}/updated-ack-v2.pdf",
    );

    expect(fn () => app(SubmitDocumentRecipientAcknowledgement::class)->handle(
        $recipientRequest,
        ['name' => 'Employee Name', 'acknowledgement' => true],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ValidationException::class, 'updated');

    $recipientRequest->refresh();
    $instance->refresh();

    expect($recipientRequest->status)->toBe(DocumentRecipientRequestStatus::Superseded)
        ->and($recipientRequest->completed_at)->toBeNull()
        ->and($recipientRequest->result_document_instance_version_id)->toBeNull()
        ->and(countRecipientEvents($recipientRequest, DocumentRecipientRequestEventType::RequestSuperseded))->toBe(1)
        ->and($instance->versions()->count())->toBe($versionCountBefore + 1)
        ->and($instance->current_version_id)->not->toBe($version->id);
});

test('successful sign completes current request without self supersede event', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $requester = User::factory()->create();
    $create = app(CreateDocumentRecipientRequest::class);

    $signResult = $create->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    $ackResult = $create->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    app(SubmitDocumentRecipientSignature::class)->handle(
        $signResult['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    );

    $signResult['request']->refresh();
    $ackResult['request']->refresh();

    expect($signResult['request']->status)->toBe(DocumentRecipientRequestStatus::Completed)
        ->and(countRecipientEvents($signResult['request'], DocumentRecipientRequestEventType::RequestSuperseded))->toBe(0)
        ->and(countRecipientEvents($signResult['request'], DocumentRecipientRequestEventType::SignatureSubmitted))->toBe(1)
        ->and(countRecipientEvents($signResult['request'], DocumentRecipientRequestEventType::SignedVersionCreated))->toBe(1)
        ->and($ackResult['request']->status)->toBe(DocumentRecipientRequestStatus::Superseded)
        ->and(countRecipientEvents($ackResult['request'], DocumentRecipientRequestEventType::RequestSuperseded))->toBe(1);
});

test('library replacement rolls back new file and preserves old file when signing transaction fails', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $oldLibraryPath = (string) $document->file_path;
    $versionCountBefore = $instance->versions()->count();

    $requester = User::factory()->create();
    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Sign,
        $requester,
        $company->id,
    );

    DocumentRecipientSigningTransactionProbe::$afterLibrarySync = function (): void {
        throw new RuntimeException('Simulated post-library signing failure.');
    };

    expect(fn () => app(SubmitDocumentRecipientSignature::class)->handle(
        $result['request'],
        [
            'signed_name' => 'Employee Name',
            'signature_data' => validSignatureDataUri(),
            'consent' => true,
        ],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(RuntimeException::class, 'Simulated post-library signing failure');

    $document->refresh();
    $instance->refresh();
    $result['request']->refresh();

    expect($document->file_path)->toBe($oldLibraryPath)
        ->and(Storage::disk('local')->exists($oldLibraryPath))->toBeTrue()
        ->and($result['request']->status)->toBe(DocumentRecipientRequestStatus::AwaitingAction)
        ->and($result['request']->result_document_instance_version_id)->toBeNull()
        ->and($instance->current_version_id)->toBe($version->id)
        ->and($instance->versions()->count())->toBe($versionCountBefore);

    $libraryFiles = Storage::disk('local')->allFiles("employee-documents/{$company->id}/{$document->employee_id}");
    expect($libraryFiles)->toBe([$oldLibraryPath]);

    $canonicalFiles = Storage::disk('local')->allFiles("document-instances/{$company->id}");
    expect($canonicalFiles)->toHaveCount(1)
        ->and($canonicalFiles[0])->toBe($version->file_path);
});

test('request creation binds locked current version instead of stale preloaded relation', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    $document->loadMissing('documentInstance.currentVersion');
    expect($document->documentInstance?->currentVersion?->id)->toBe($version->id);

    $nextVersion = advanceDocumentInstanceCurrentVersion(
        $instance,
        "document-instances/{$company->id}/locked-current.pdf",
    );

    $requester = User::factory()->create();
    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );

    expect($result['request']->source_document_instance_version_id)->toBe($nextVersion->id)
        ->and($result['request']->source_document_instance_version_id)->not->toBe($version->id)
        ->and($result['request']->source_checksum_sha256)->toBe($nextVersion->checksum);
});

test('old approved workflow on previous version does not authorize newer current version', function () {
    ['company' => $company, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeRecipientFixturesWithSignaturePlacement(
        defaultSignaturePlacementConfig(),
    );

    DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $version->id,
        'status' => DocumentWorkflowRequestStatus::Approved,
        'requested_by' => User::factory()->create()->id,
        'requester_name_snapshot' => 'HR',
        'requested_at' => now(),
    ]);

    $nextVersion = advanceDocumentInstanceCurrentVersion(
        $instance,
        "document-instances/{$company->id}/next-version.pdf",
    );

    DocumentWorkflowRequest::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'document_instance_version_id' => $nextVersion->id,
        'status' => DocumentWorkflowRequestStatus::Pending,
        'requested_by' => User::factory()->create()->id,
        'requester_name_snapshot' => 'HR',
        'requested_at' => now(),
    ]);

    $requester = User::factory()->create();

    app(CreateDocumentRecipientRequest::class)->handle(
        $document,
        DocumentRecipientAction::Acknowledge,
        $requester,
        $company->id,
    );
})->throws(ValidationException::class);

test('users without documents.recipient-requests.create cannot create recipient requests', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.recipient-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'action' => 'acknowledge',
        ])
        ->assertForbidden();
});
