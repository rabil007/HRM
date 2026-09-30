<?php

use App\Contracts\EmployeeDocuments\DocumentAiExtractor;
use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiMode;
use App\Jobs\ExtractDocumentAiBatchItemJob;
use App\Models\DocumentAiBatch;
use App\Models\DocumentAiBatchItem;
use App\Models\DocumentAiSetting;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\DocumentAiExtractionService;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function enableBatchAi($company, User $user): void
{
    DocumentAiSetting::query()->updateOrCreate(
        ['company_id' => $company->id],
        [
            'mode' => DocumentAiMode::Optional,
            'updated_by' => $user->id,
        ],
    );
    storePlatformAiSettings(['openai_api_key' => 'batch-test-key'], $user);
}

function createQueuedBatch(User $user, $company, $employee, int $files = 1): DocumentAiBatch
{
    Queue::fake();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);

    $draftIds = [];
    $uploads = [];
    for ($i = 0; $i < $files; $i++) {
        $draftIds[] = sprintf('11111111-1111-4111-8111-%012d', $i + 1);
        $uploads[] = UploadedFile::fake()->create("file-{$i}.pdf", 100, 'application/pdf');
    }

    $response = test()->actingAs($user)->postJson(
        route('organization.employees.documents.ai-batches.store', $employee),
        ['draft_ids' => $draftIds, 'files' => $uploads],
    )->assertAccepted();

    return DocumentAiBatch::query()->with('items')->findOrFail($response->json('batch.id'));
}

test('creates a tenant owned batch and dispatches one job per valid file', function () {
    Queue::fake();
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);

    $response = $this->actingAs($user)->postJson(
        route('organization.employees.documents.ai-batches.store', $employee),
        [
            'draft_ids' => [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
            ],
            'files' => [
                UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('eid.jpg'),
            ],
        ],
    )->assertAccepted()->assertJsonPath('batch.total', 2);

    $batch = DocumentAiBatch::query()->with('items')->findOrFail($response->json('batch.id'));
    expect($batch->company_id)->toBe($company->id)
        ->and($batch->user_id)->toBe($user->id);

    Queue::assertPushed(ExtractDocumentAiBatchItemJob::class, 2);
    expect(serialize(new ExtractDocumentAiBatchItemJob($batch->items->first()->id)))
        ->not->toContain('batch-test-key');

    expect(Activity::query()->where('event', 'document_ai_batch_started')->where('company_id', $company->id)->exists())
        ->toBeTrue();
});

test('off mode and missing permission cannot create a batch', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    $payload = [
        'draft_ids' => ['11111111-1111-4111-8111-111111111111'],
        'files' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf')],
    ];

    $this->actingAs($user)
        ->postJson(route('organization.employees.documents.ai-batches.store', $employee), $payload)
        ->assertServiceUnavailable();

    $other = User::factory()->create();
    $this->actingAs($other)
        ->postJson(route('organization.employees.documents.ai-batches.store', $employee), $payload)
        ->assertForbidden();
});

test('batch job stores normalized result without creating documents or mutating employee', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);
    $before = $employee->fresh()->only(['name', 'passport_number', 'emirates_id']);

    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            return DocumentAiExtractionResult::fromDecoded([
                'document_type' => 'passport',
                'confidence' => .9,
                'fields' => [
                    'document_number' => ['value' => 'P1', 'confidence' => .9],
                ],
                'warnings' => [],
            ]);
        }
    });

    app(ExtractDocumentAiBatchItemJob::class, ['itemId' => $batch->items->first()->id])
        ->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));

    expect($batch->items->first()->fresh()->normalized_result_json['fields']['document_number']['value'])
        ->toBe('P1')
        ->and(EmployeeDocument::query()->count())->toBe(0)
        ->and($employee->fresh()->only(['name', 'passport_number', 'emirates_id']))->toBe($before);
});

test('batch progress is private to company and initiating user', function () {
    $owner = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($owner, $company, $employee);

    $other = User::factory()->create();
    grantCompanyPermissions($other, $company, ['documents.ai.use'], 'other-ai');
    $this->actingAs($other)
        ->getJson(route('organization.documents.ai-batches.show', $batch))
        ->assertNotFound();
});

test('cross company users cannot access another company batch status retry or cancel', function () {
    $owner = User::factory()->create();
    ['company' => $companyA, 'employee' => $employeeA] = makeDocumentFixtures();
    $batch = createQueuedBatch($owner, $companyA, $employeeA);
    $item = $batch->items->first();
    $item->update(['status' => DocumentAiBatchItemStatus::Failed, 'safe_error_code' => 'extraction_failed']);

    $intruder = User::factory()->create();
    ['company' => $companyB] = makeDocumentFixtures();
    grantCompanyPermissions($intruder, $companyB, ['documents.ai.use'], 'company-b-ai');
    enableBatchAi($companyB, $intruder);

    $this->actingAs($intruder)
        ->withSession(['current_company_id' => $companyB->id])
        ->getJson(route('organization.documents.ai-batches.show', $batch))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->withSession(['current_company_id' => $companyB->id])
        ->postJson(route('organization.documents.ai-batches.retry', ['batch' => $batch, 'item' => $item->id]))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->withSession(['current_company_id' => $companyB->id])
        ->postJson(route('organization.documents.ai-batches.cancel', $batch))
        ->assertNotFound();
});

test('company a cannot create a batch for a company b employee', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyA, ['documents.ai.use']);
    enableBatchAi($companyA, $user);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->postJson(route('organization.employees.documents.ai-batches.store', $employeeB), [
            'company_id' => $companyB->id,
            'draft_ids' => ['11111111-1111-4111-8111-111111111111'],
            'files' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf')],
        ])
        ->assertForbidden();
});

test('cancel keeps batch cancelled even if lifecycle refresh is called later', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);

    $this->actingAs($user)
        ->postJson(route('organization.documents.ai-batches.cancel', $batch))
        ->assertOk()
        ->assertJsonPath('batch.status', 'cancelled');

    DocumentAiBatchLifecycle::refresh($batch->fresh());

    expect($batch->fresh()->status)->toBe(DocumentAiBatchStatus::Cancelled)
        ->and(Activity::query()->where('event', 'document_ai_batch_cancelled')->where('company_id', $company->id)->exists())
        ->toBeTrue();
});

test('cleanup skips batches with processing items and is idempotent for expired idle batches', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee, 2);
    $items = $batch->items->values();
    $items[0]->update(['status' => DocumentAiBatchItemStatus::Processing]);
    $items[1]->update(['status' => DocumentAiBatchItemStatus::Queued]);
    $batch->update(['expires_at' => now()->subMinute()]);

    Artisan::call('documents:cleanup-ai-batches');
    expect(DocumentAiBatch::query()->whereKey($batch->id)->exists())->toBeTrue();

    $items[0]->update(['status' => DocumentAiBatchItemStatus::Failed, 'completed_at' => now()]);
    $items[1]->update(['status' => DocumentAiBatchItemStatus::Failed, 'completed_at' => now()]);

    Artisan::call('documents:cleanup-ai-batches');
    expect(DocumentAiBatch::query()->whereKey($batch->id)->exists())->toBeFalse();

    Artisan::call('documents:cleanup-ai-batches');
    expect(DocumentAiBatchItem::query()->count())->toBe(0);
});

test('permanent invalid output fails the item without creating documents', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);

    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            return DocumentAiExtractionResult::fromDecoded([
                'document_type' => 'not-a-type',
                'confidence' => 1,
                'fields' => [],
                'warnings' => [],
            ]);
        }
    });

    app(ExtractDocumentAiBatchItemJob::class, ['itemId' => $batch->items->first()->id])
        ->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));

    $item = $batch->items->first()->fresh();
    expect($item->status)->toBe(DocumentAiBatchItemStatus::Failed)
        ->and($item->safe_error_code)->toBe('invalid_output')
        ->and(EmployeeDocument::query()->count())->toBe(0);
});

test('job rejects path traversal temporary references', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);
    $item = $batch->items->first();
    $item->update(['temporary_file_reference' => '../../.env']);

    app(ExtractDocumentAiBatchItemJob::class, ['itemId' => $item->id])
        ->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));

    expect($item->fresh()->status)->toBe(DocumentAiBatchItemStatus::Failed)
        ->and($item->fresh()->safe_error_code)->toBe('temporary_file_missing');
});
