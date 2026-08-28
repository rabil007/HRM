<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentWorkflowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientAcknowledgement;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function makeRecipientFixturesWithSignaturePlacement(?array $signaturePlacement = null): array
{
    if ($signaturePlacement === null) {
        return makeGeneratedDocumentWorkflowFixtures();
    }

    $company = makeDocumentFixtures()['company'];
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $templateVersion = DocumentGenerationTemplateVersion::factory()
        ->forTemplate($template)
        ->published()
        ->create([
            'signature_placement_config' => $signaturePlacement,
        ]);
    $template->update(['published_version_id' => $templateVersion->id]);

    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/letter.pdf";
    $canonicalPath = "document-instances/{$company->id}/canonical.pdf";
    $pdfBytes = minimalPdfBytes();
    Storage::disk('local')->put($libraryPath, $pdfBytes);
    Storage::disk('local')->put($canonicalPath, $pdfBytes);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Generated Letter',
        'file_path' => $libraryPath,
        'original_filename' => 'letter.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes),
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
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes),
    ]);

    $instance->update(['current_version_id' => $version->id]);

    return compact('company', 'employee', 'document', 'instance', 'version', 'template');
}

function defaultSignaturePlacementConfig(): array
{
    return [
        'schema_version' => 1,
        'placements' => [[
            'id' => 'subject_signature',
            'type' => 'signature',
            'role' => 'subject',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.75,
            'width' => 0.25,
            'height' => 0.08,
            'required' => true,
        ]],
    ];
}

function validSignatureDataUri(): string
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    return 'data:image/png;base64,'.base64_encode($png ?: '');
}

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
