<?php

use App\Contracts\EmployeeDocuments\DocumentAiExtractor;
use App\Enums\DocumentAiMode;
use App\Jobs\ExtractDocumentAiBatchItemJob;
use App\Models\DocumentAiBatch;
use App\Models\DocumentAiSetting;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\DocumentAiExtractionService;
use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});
function enableBatchAi($company, User $user): void
{
    DocumentAiSetting::query()->create(['company_id' => $company->id, 'mode' => DocumentAiMode::Optional, 'updated_by' => $user->id]);
    storePlatformAiSettings(['openai_api_key' => 'batch-test-key'], $user);
}

test('creates a tenant owned batch and dispatches one job per valid file', function () {
    Queue::fake();
    $user = User::factory()->create();
    ['company' => $company,'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);
    $response = $this->actingAs($user)->postJson(route('organization.employees.documents.ai-batches.store', $employee), ['draft_ids' => ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'], 'files' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'), UploadedFile::fake()->image('eid.jpg')]])->assertAccepted()->assertJsonPath('batch.total', 2);
    $batch = DocumentAiBatch::query()->with('items')->findOrFail($response->json('batch.id'));
    expect($batch->company_id)->toBe($company->id)->and($batch->user_id)->toBe($user->id);
    Queue::assertPushed(ExtractDocumentAiBatchItemJob::class, 2);
    expect(serialize(new ExtractDocumentAiBatchItemJob($batch->items->first()->id)))->not->toContain('batch-test-key');
});

test('off mode and missing permission cannot create a batch', function () {
    $user = User::factory()->create();
    ['company' => $company,'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    $payload = ['draft_ids' => ['11111111-1111-4111-8111-111111111111'], 'files' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf')]];
    $this->actingAs($user)->postJson(route('organization.employees.documents.ai-batches.store', $employee), $payload)->assertServiceUnavailable();
    $other = User::factory()->create();
    $this->actingAs($other)->postJson(route('organization.employees.documents.ai-batches.store', $employee), $payload)->assertForbidden();
});

test('batch job stores normalized result without creating documents or mutating employee', function () {
    $user = User::factory()->create();
    ['company' => $company,'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableBatchAi($company, $user);
    Queue::fake();
    $response = $this->actingAs($user)->postJson(route('organization.employees.documents.ai-batches.store', $employee), ['draft_ids' => ['11111111-1111-4111-8111-111111111111'], 'files' => [UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf')]]);
    $batch = DocumentAiBatch::query()->with('items')->findOrFail($response->json('batch.id'));
    $before = $employee->fresh()->only(['name', 'passport_number', 'emirates_id']);
    app()->instance(DocumentAiExtractor::class, new class implements DocumentAiExtractor
    {
        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            return DocumentAiExtractionResult::fromDecoded(['document_type' => 'passport', 'confidence' => .9, 'fields' => ['document_number' => ['value' => 'P1', 'confidence' => .9]], 'warnings' => []]);
        }
    });
    app(ExtractDocumentAiBatchItemJob::class, ['itemId' => $batch->items->first()->id])->handle(app(DocumentAiExtractionService::class), app(DocumentAiSettings::class));
    expect($batch->items->first()->fresh()->normalized_result_json['fields']['document_number']['value'])->toBe('P1')->and(EmployeeDocument::query()->count())->toBe(0)->and($employee->fresh()->only(['name', 'passport_number', 'emirates_id']))->toBe($before);
});

test('batch progress is private to company and initiating user', function () {
    Queue::fake();
    $owner = User::factory()->create();
    ['company' => $company,'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($owner, $company, ['documents.ai.use']);
    enableBatchAi($company, $owner);
    $r = $this->actingAs($owner)->postJson(route('organization.employees.documents.ai-batches.store', $employee), ['draft_ids' => ['11111111-1111-4111-8111-111111111111'], 'files' => [UploadedFile::fake()->create('p.pdf', 100, 'application/pdf')]]);
    $batch = DocumentAiBatch::findOrFail($r->json('batch.id'));
    $other = User::factory()->create();
    grantCompanyPermissions($other, $company, ['documents.ai.use'], 'other-ai');
    $this->actingAs($other)->getJson(route('organization.documents.ai-batches.show', $batch))->assertNotFound();
});
