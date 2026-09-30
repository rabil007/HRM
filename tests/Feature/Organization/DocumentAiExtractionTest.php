<?php

use App\Contracts\EmployeeDocuments\DocumentAiExtractor;
use App\Enums\DocumentAiMode;
use App\Models\DocumentAiSetting;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\DocumentAiExtractionService;
use App\Services\DocumentAiProviderExtractor;
use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

function enableDocumentExtractionFor(object $company, User $user, DocumentAiMode $mode = DocumentAiMode::Optional): void
{
    DocumentAiSetting::query()->create(['company_id' => $company->id, 'mode' => $mode, 'updated_by' => $user->id]);
    storePlatformAiSettings(['openai_api_key' => 'test-document-key'], $user);
}

function fakeDocumentExtractor(DocumentAiExtractionResult|Throwable $result): void
{
    app()->instance(DocumentAiExtractor::class, new class($result) implements DocumentAiExtractor
    {
        public function __construct(private DocumentAiExtractionResult|Throwable $result) {}

        public function extract(UploadedFile $file): DocumentAiExtractionResult
        {
            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return $this->result;
        }
    });
}

test('production container resolves document AI service and extractor', function () {
    expect(app(DocumentAiExtractor::class))->toBeInstanceOf(DocumentAiProviderExtractor::class)
        ->and(app(DocumentAiExtractionService::class))->toBeInstanceOf(DocumentAiExtractionService::class);
});

test('automatic mode is exposed to the document upload frontend', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'documents.upload', 'documents.ai.use']);
    enableDocumentExtractionFor($company, $user, DocumentAiMode::Automatic);

    $this->actingAs($user)->get(route('organization.documents.library'))->assertOk()->assertInertia(
        fn (Assert $page) => $page->where('document_ai_settings.mode', 'automatic')->where('can.ai_use', true),
    );
});

test('provider unavailable returns a safe failure', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    DocumentAiSetting::query()->create(['company_id' => $company->id, 'mode' => DocumentAiMode::Optional, 'updated_by' => $user->id]);

    $this->actingAs($user)->postJson(route('organization.employees.documents.ai-extract', $employee), [
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertServiceUnavailable()->assertJson(['message' => 'Document AI is temporarily unavailable.']);
});

test('optional mode extracts without persisting or mutating employee', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableDocumentExtractionFor($company, $user);
    $original = $employee->fresh()->only(['name', 'nationality_id', 'emirates_id', 'passport_number']);
    fakeDocumentExtractor(DocumentAiExtractionResult::fromDecoded([
        'document_type' => 'passport', 'confidence' => .94,
        'fields' => ['document_number' => ['value' => 'P123', 'confidence' => .98]], 'warnings' => [],
    ]));

    $this->actingAs($user)->postJson(route('organization.employees.documents.ai-extract', $employee), [
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertOk()->assertJsonPath('result.document_type', 'passport')->assertJsonPath('result.fields.document_number.value', 'P123');

    expect(EmployeeDocument::query()->count())->toBe(0)
        ->and($employee->fresh()->only(['name', 'nationality_id', 'emirates_id', 'passport_number']))->toBe($original);
});

test('provider failure is safe and does not expose its message', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableDocumentExtractionFor($company, $user);
    fakeDocumentExtractor(new RuntimeException('secret provider payload'));

    $this->actingAs($user)->postJson(route('organization.employees.documents.ai-extract', $employee), [
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertServiceUnavailable()->assertJsonMissing(['secret provider payload']);
});

test('extraction validates file type and size', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);
    enableDocumentExtractionFor($company, $user);

    $this->actingAs($user)->postJson(route('organization.employees.documents.ai-extract', $employee), [
        'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->actingAs($user)->postJson(route('organization.employees.documents.ai-extract', $employee), [
        'file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('cross-company employee is blocked and submitted company id cannot switch tenant', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyA, ['documents.ai.use']);
    enableDocumentExtractionFor($companyA, $user);

    $this->actingAs($user)->withSession(['current_company_id' => $companyA->id])->postJson(route('organization.employees.documents.ai-extract', $employeeB), [
        'company_id' => $companyB->id,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

test('documents ai use permission does not grant upload permission', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee, 'passportType' => $type] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.use']);

    $this->actingAs($user)->post(route('organization.employees.documents.store', $employee), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});
