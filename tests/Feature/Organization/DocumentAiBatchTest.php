<?php

use App\Contracts\EmployeeDocuments\DocumentAiExtractor;
use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiErrorCode;
use App\Enums\DocumentAiMode;
use App\Exceptions\DocumentAiProviderException;
use App\Jobs\ExtractDocumentAiBatchItemJob;
use App\Models\DocumentAiBatch;
use App\Models\DocumentAiBatchItem;
use App\Models\DocumentAiSetting;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\DocumentAiExtractionService;
use App\Support\EmployeeDocuments\CreateDocumentAiBatch;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

function batchRequestId(): string
{
    return (string) Str::uuid();
}

function createQueuedBatch(User $user, $company, $employee, int $files = 1, ?string $requestId = null): DocumentAiBatch
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
        [
            'batch_request_id' => $requestId ?? batchRequestId(),
            'draft_ids' => $draftIds,
            'files' => $uploads,
        ],
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
            'batch_request_id' => batchRequestId(),
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
});

test('off mode and missing permission cannot create a batch', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    $payload = [
        'batch_request_id' => batchRequestId(),
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

test('duplicate batch request id reuses the existing batch without extra jobs', function () {
    Queue::fake();
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);
    $requestId = batchRequestId();
    $payload = [
        'batch_request_id' => $requestId,
        'draft_ids' => ['11111111-1111-4111-8111-111111111111'],
        'files' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf')],
    ];

    $first = $this->actingAs($user)
        ->postJson(route('organization.employees.documents.ai-batches.store', $employee), $payload)
        ->assertAccepted();

    Queue::assertPushed(ExtractDocumentAiBatchItemJob::class, 1);

    $this->actingAs($user)
        ->postJson(route('organization.employees.documents.ai-batches.store', $employee), $payload)
        ->assertAccepted()
        ->assertJsonPath('reused', true)
        ->assertJsonPath('batch.id', $first->json('batch.id'));

    expect(DocumentAiBatch::query()->count())->toBe(1);
    Queue::assertPushed(ExtractDocumentAiBatchItemJob::class, 1);
});

test('same batch request id for a different employee creates an independent batch', function () {
    Queue::fake();
    $user = User::factory()->create();
    ['company' => $company, 'branch' => $branch, 'employee' => $employeeA] = makeDocumentFixtures();
    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $employeeA->department_id,
        'employee_no' => 'DOC-AI-B',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);
    $requestId = batchRequestId();

    $batchA = $this->actingAs($user)->postJson(
        route('organization.employees.documents.ai-batches.store', $employeeA),
        [
            'batch_request_id' => $requestId,
            'draft_ids' => ['11111111-1111-4111-8111-111111111111'],
            'files' => [UploadedFile::fake()->create('a.pdf', 100, 'application/pdf')],
        ],
    )->assertAccepted()->json('batch.id');

    $batchB = $this->actingAs($user)->postJson(
        route('organization.employees.documents.ai-batches.store', $employeeB),
        [
            'batch_request_id' => $requestId,
            'draft_ids' => ['22222222-2222-4222-8222-222222222222'],
            'files' => [UploadedFile::fake()->create('b.pdf', 100, 'application/pdf')],
        ],
    )->assertAccepted()->json('batch.id');

    expect($batchA)->not->toBe($batchB)
        ->and(DocumentAiBatch::query()->findOrFail($batchA)->employee_id)->toBe($employeeA->id)
        ->and(DocumentAiBatch::query()->findOrFail($batchB)->employee_id)->toBe($employeeB->id)
        ->and(DocumentAiBatch::query()->count())->toBe(2);

    Queue::assertPushed(ExtractDocumentAiBatchItemJob::class, 2);
});

test('mixed supported and unsupported files partially accept', function () {
    Queue::fake();
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);

    $response = $this->actingAs($user)->postJson(
        route('organization.employees.documents.ai-batches.store', $employee),
        [
            'batch_request_id' => batchRequestId(),
            'draft_ids' => [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '33333333-3333-4333-8333-333333333333',
            ],
            'files' => [
                UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('eid.jpg'),
                UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ],
        ],
    )->assertAccepted()
        ->assertJsonPath('batch.total', 2)
        ->assertJsonPath('rejected.0.draft_id', '33333333-3333-4333-8333-333333333333');

    Queue::assertPushed(ExtractDocumentAiBatchItemJob::class, 2);
});

test('mismatched files and draft ids are rejected', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);

    $this->actingAs($user)->postJson(
        route('organization.employees.documents.ai-batches.store', $employee),
        [
            'batch_request_id' => batchRequestId(),
            'draft_ids' => ['11111111-1111-4111-8111-111111111111'],
            'files' => [
                UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('eid.jpg'),
            ],
        ],
    )->assertUnprocessable()->assertJsonValidationErrors('draft_ids');
});

test('failed batch creation after temp storage removes written files', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);

    $calls = 0;
    DocumentAiBatchItem::creating(function () use (&$calls): void {
        $calls++;
        if ($calls === 2) {
            throw new RuntimeException('simulated item create failure');
        }
    });

    $accepted = [
        [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), '11111111-1111-4111-8111-111111111111'],
        [UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), '22222222-2222-4222-8222-222222222222'],
    ];

    expect(fn () => app(CreateDocumentAiBatch::class)->handle(
        $company->id,
        $user,
        $employee,
        batchRequestId(),
        $accepted,
    ))->toThrow(RuntimeException::class);

    expect(DocumentAiBatch::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
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

test('wrapped provider exceptions keep retryable categories', function (string $innerMessage, DocumentAiErrorCode $expected) {
    $wrapped = new DocumentAiProviderException(
        DocumentAiProviderException::classify(new RuntimeException($innerMessage)),
        new RuntimeException($innerMessage),
    );

    expect(DocumentAiProviderException::classify($wrapped))->toBe($expected)
        ->and($expected->isRetryable())->toBeTrue();
})->with([
    'timeout' => ['cURL error 28: Operation timed out', DocumentAiErrorCode::ProviderTimeout],
    '429' => ['HTTP 429 Too Many Requests rate limit', DocumentAiErrorCode::ProviderRateLimited],
    '503' => ['HTTP 503 Service Unavailable', DocumentAiErrorCode::ProviderUnavailable],
]);

test('wrapped invalid output is permanent and increments attempts', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);

    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            throw new DocumentAiProviderException(
                DocumentAiErrorCode::InvalidOutput,
                new InvalidArgumentException('bad schema'),
            );
        }
    });

    app(ExtractDocumentAiBatchItemJob::class, ['itemId' => $batch->items->first()->id])
        ->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));

    $item = $batch->items->first()->fresh();
    expect($item->status)->toBe(DocumentAiBatchItemStatus::Failed)
        ->and($item->safe_error_code)->toBe('invalid_output')
        ->and($item->attempts)->toBe(1);
});

test('provider unavailable increments attempts and respects retry limit', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);
    $item = $batch->items->first();

    storePlatformAiSettings([
        'provider' => 'not-a-real-provider',
        'openai_api_key' => 'batch-test-key',
    ], $user);

    expect(app(DocumentAiSettings::class)->providerAvailable())->toBeFalse();

    $job = new class($item->id) extends ExtractDocumentAiBatchItemJob
    {
        public function attempts(): int
        {
            return 3;
        }
    };

    $job->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));

    expect($item->fresh()->attempts)->toBe(1)
        ->and($item->fresh()->status)->toBe(DocumentAiBatchItemStatus::Failed)
        ->and($item->fresh()->safe_error_code)->toBe('provider_unavailable');

    $item->refresh()->update([
        'attempts' => ExtractDocumentAiBatchItemJob::MAX_ITEM_ATTEMPTS,
        'status' => DocumentAiBatchItemStatus::Failed,
        'safe_error_code' => 'provider_unavailable',
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.documents.ai-batches.retry', ['batch' => $batch, 'item' => $item->id]))
        ->assertStatus(409);
});

test('retryable wrapped timeout requeues while attempts remain', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);

    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            throw DocumentAiProviderException::fromThrowable(new RuntimeException('gateway timeout after 30s'));
        }
    });

    $job = new class($batch->items->first()->id) extends ExtractDocumentAiBatchItemJob
    {
        public function attempts(): int
        {
            return 1;
        }
    };

    expect(fn () => $job->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class)))
        ->toThrow(DocumentAiProviderException::class);

    $item = $batch->items->first()->fresh();
    expect($item->status)->toBe(DocumentAiBatchItemStatus::Queued)
        ->and($item->attempts)->toBe(1)
        ->and($item->safe_error_code)->toBeNull();
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
            'batch_request_id' => batchRequestId(),
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

    expect($batch->fresh()->status)->toBe(DocumentAiBatchStatus::Cancelled);
});

test('cleanup skips recent processing and purges stale processing', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $recent = createQueuedBatch($user, $company, $employee);
    $recent->items->first()->update([
        'status' => DocumentAiBatchItemStatus::Processing,
        'started_at' => now()->subSeconds(30),
    ]);
    $recent->update(['expires_at' => now()->subMinute()]);

    Artisan::call('documents:cleanup-ai-batches');
    expect(DocumentAiBatch::query()->whereKey($recent->id)->exists())->toBeTrue();

    $stale = createQueuedBatch($user, $company, $employee, 1, batchRequestId());
    $stalePath = $stale->items->first()->temporary_file_reference;
    $stale->items->first()->update([
        'status' => DocumentAiBatchItemStatus::Processing,
        'started_at' => now()->subSeconds(DocumentAiBatchLifecycle::staleProcessingThresholdSeconds() + 10),
    ]);
    $stale->update(['expires_at' => now()->subMinute()]);

    Artisan::call('documents:cleanup-ai-batches');
    expect(DocumentAiBatch::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($stalePath))->toBeFalse();

    Artisan::call('documents:cleanup-ai-batches');
});

test('cleanup removes orphan temp directories after parent cascade delete', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);
    $path = $batch->items->first()->temporary_file_reference;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    // Simulate DB cascade deleting the batch row without Eloquent events.
    DocumentAiBatchItem::query()->where('document_ai_batch_id', $batch->id)->delete();
    DocumentAiBatch::query()->whereKey($batch->id)->delete();
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    Artisan::call('documents:cleanup-ai-batches');
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('cleanup purges expired completed cancelled and failed batches', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    foreach ([
        DocumentAiBatchStatus::Completed,
        DocumentAiBatchStatus::Cancelled,
        DocumentAiBatchStatus::CompletedWithErrors,
    ] as $status) {
        $batch = createQueuedBatch($user, $company, $employee, 1, batchRequestId());
        $batch->items->first()->update([
            'status' => DocumentAiBatchItemStatus::Failed,
            'completed_at' => now(),
        ]);
        $batch->update([
            'status' => $status,
            'expires_at' => now()->subMinute(),
        ]);
    }

    Artisan::call('documents:cleanup-ai-batches');
    expect(DocumentAiBatch::query()->count())->toBe(0);
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
        ->and($item->fresh()->safe_error_code)->toBe('temporary_file_missing')
        ->and($item->fresh()->attempts)->toBe(1);
});

test('job reclaims processing items on a subsequent attempt after worker timeout', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $batch = createQueuedBatch($user, $company, $employee);
    $item = $batch->items->first();
    $item->update([
        'status' => DocumentAiBatchItemStatus::Processing,
        'started_at' => now()->subMinute(),
        'attempts' => 1,
    ]);

    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            return DocumentAiExtractionResult::fromDecoded([
                'document_type' => 'passport',
                'confidence' => .8,
                'fields' => [
                    'document_number' => ['value' => 'RECLAIM', 'confidence' => .8],
                ],
                'warnings' => [],
            ]);
        }
    });

    $firstAttempt = new ExtractDocumentAiBatchItemJob($item->id);
    $firstAttempt->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));
    expect($item->fresh()->status)->toBe(DocumentAiBatchItemStatus::Processing);

    $retry = new class($item->id) extends ExtractDocumentAiBatchItemJob
    {
        public function attempts(): int
        {
            return 2;
        }
    };
    $retry->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));

    expect($item->fresh()->status)->toBe(DocumentAiBatchItemStatus::Completed)
        ->and($item->fresh()->normalized_result_json['fields']['document_number']['value'])->toBe('RECLAIM');
});
