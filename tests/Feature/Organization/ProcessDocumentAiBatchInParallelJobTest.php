<?php

use App\Contracts\EmployeeDocuments\DocumentAiExtractor;
use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiMode;
use App\Jobs\ProcessDocumentAiBatchInParallelJob;
use App\Models\DocumentAiBatch;
use App\Models\DocumentAiSetting;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
    // Process/fork drivers spawn subprocesses that cannot see sqlite :memory:.
    config(['concurrency.default' => 'sync']);
});

test('parallel batch job extracts queued items via dispatchSync fan-out', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    DocumentAiSetting::query()->updateOrCreate(
        ['company_id' => $company->id],
        [
            'mode' => DocumentAiMode::Optional,
            'updated_by' => $user->id,
        ],
    );
    storePlatformAiSettings(['openai_api_key' => 'parallel-test-key'], $user);

    $batch = DocumentAiBatch::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'employee_id' => $employee->id,
        'batch_request_id' => (string) Str::uuid(),
        'status' => DocumentAiBatchStatus::Pending,
        'total_items' => 2,
        'expires_at' => now()->addHour(),
    ]);

    foreach (['a.pdf', 'b.pdf'] as $name) {
        $path = "document-ai-temp/{$company->id}/{$batch->id}/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, 'pdf-bytes');
        $batch->items()->create([
            'client_draft_id' => (string) Str::uuid(),
            'status' => DocumentAiBatchItemStatus::Queued,
            'temporary_file_reference' => $path,
            'original_filename' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => 9,
        ]);
    }

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

    (new ProcessDocumentAiBatchInParallelJob($batch->id))->handle();

    $batch->refresh()->load('items');
    expect($batch->status)->toBe(DocumentAiBatchStatus::Completed)
        ->and($batch->completed_items)->toBe(2)
        ->and($batch->items->every(fn ($item) => $item->status === DocumentAiBatchItemStatus::Completed))->toBeTrue();
});

test('parallel batch job skips cancelled batches', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    $batch = DocumentAiBatch::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'employee_id' => $employee->id,
        'batch_request_id' => (string) Str::uuid(),
        'status' => DocumentAiBatchStatus::Cancelled,
        'total_items' => 1,
        'expires_at' => now()->addHour(),
    ]);

    $path = "document-ai-temp/{$company->id}/{$batch->id}/".Str::uuid().'.pdf';
    Storage::disk('local')->put($path, 'pdf-bytes');
    $batch->items()->create([
        'client_draft_id' => (string) Str::uuid(),
        'status' => DocumentAiBatchItemStatus::Queued,
        'temporary_file_reference' => $path,
        'original_filename' => 'a.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 9,
    ]);

    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            throw new RuntimeException('should not extract');
        }
    });

    (new ProcessDocumentAiBatchInParallelJob($batch->id))->handle();

    expect($batch->fresh()->items()->first()->status)->toBe(DocumentAiBatchItemStatus::Queued);
});
