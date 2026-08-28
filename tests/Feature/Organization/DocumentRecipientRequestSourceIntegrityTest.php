<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestEventType;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientAcknowledgement;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

test('acknowledgement rejects canonical bytes that no longer match the bound checksum', function () {
    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $requester = User::factory()->create();

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $fixtures['document'],
        DocumentRecipientAction::Acknowledge,
        $requester,
        $fixtures['company']->id,
    );

    Storage::disk('local')->put($fixtures['version']->file_path, '%PDF-1.4 tampered');

    expect(fn () => app(SubmitDocumentRecipientAcknowledgement::class)->handle(
        $result['request'],
        ['name' => 'Employee Name', 'acknowledgement' => true],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ValidationException::class, 'integrity');

    $result['request']->refresh();

    expect($result['request']->completed_at)->toBeNull()
        ->and($result['request']->events()
            ->where('event', DocumentRecipientRequestEventType::AcknowledgementSubmitted->value)
            ->exists())->toBeFalse();
});

test('acknowledgement rejects a source version attached to another document instance', function () {
    $first = makeGeneratedDocumentWorkflowFixtures();
    $second = makeGeneratedDocumentWorkflowFixtures($first['company']);
    $requester = User::factory()->create();

    $result = app(CreateDocumentRecipientRequest::class)->handle(
        $first['document'],
        DocumentRecipientAction::Acknowledge,
        $requester,
        $first['company']->id,
    );

    $request = $result['request'];
    $request->update([
        'source_document_instance_version_id' => $second['version']->id,
        'source_checksum_sha256' => $second['version']->checksum,
    ]);

    expect(fn () => app(SubmitDocumentRecipientAcknowledgement::class)->handle(
        $request->fresh(),
        ['name' => 'Employee Name', 'acknowledgement' => true],
        Request::create('/document-action/test', 'POST'),
    ))->toThrow(ModelNotFoundException::class);
});
