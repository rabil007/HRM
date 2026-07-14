<?php

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentVersion;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function companyDocumentPdf(string $name = 'trade-license.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, minimalPdfBytes());
}

/** @return array{company: Company, type: DocumentType, user: User} */
function companyDocumentContext(array $permissions): array
{
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($user, $fixtures['company'], $permissions);

    return [
        'company' => $fixtures['company'],
        'type' => $fixtures['passportType'],
        'user' => $user,
    ];
}

function storedCompanyDocument(Company $company, DocumentType $type, User $user, array $overrides = []): CompanyDocument
{
    $path = "company-documents/{$company->id}/stored.pdf";
    Storage::disk('local')->put($path, minimalPdfBytes());

    return CompanyDocument::query()->create([
        'company_id' => $company->id,
        'document_type_id' => $type->id,
        'title' => 'Trade License',
        'document_number' => 'TL-100',
        'file_path' => $path,
        'original_filename' => 'stored.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(minimalPdfBytes()),
        'checksum' => hash('sha256', minimalPdfBytes()),
        'current_version' => 1,
        'uploaded_by' => $user->id,
        ...$overrides,
    ]);
}

test('guests are redirected and non-members receive not found', function () {
    $fixtures = makeDocumentFixtures();

    $this->get(route('organization.companies.documents.index', $fixtures['company']))
        ->assertRedirect(route('login'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $fixtures['company']))
        ->assertNotFound();
});

test('active members without the target company permission receive forbidden and inactive members receive not found', function () {
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create();

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $fixtures['company']))
        ->assertForbidden();

    DB::table('company_user')->where('user_id', $user->id)->update(['status' => 'inactive']);

    $this->get(route('organization.companies.documents.index', $fixtures['company']))
        ->assertNotFound();
});

test('upload permission stores validated files on the private company path', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext(['company_documents.upload']);

    $this->actingAs($user)
        ->post(route('organization.companies.documents.store', $company), [
            'document_type_id' => $type->id,
            'title' => '2026 Trade License',
            'document_number' => 'TL-2026',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2026-12-31',
            'file' => companyDocumentPdf(),
        ])
        ->assertRedirect();

    $document = CompanyDocument::query()->sole();

    expect($document->file_path)
        ->toStartWith("company-documents/{$company->id}/")
        ->and($document->checksum)->toHaveLength(64)
        ->and($document->uploaded_by)->toBe($user->id);
    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('public')->assertMissing($document->file_path);
});

test('content based validation rejects disguised and oversized files', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext(['company_documents.upload']);

    $this->actingAs($user)
        ->post(route('organization.companies.documents.store', $company), [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->createWithContent('disguised.pdf', 'not a pdf'),
        ])
        ->assertSessionHasErrors('file');

    $this->post(route('organization.companies.documents.store', $company), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('large.pdf', 20 * 1024 + 1, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    expect(CompanyDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty("company-documents/{$company->id}");
});

test('bulk upload validates every item before storing any file', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext(['company_documents.upload']);

    $this->actingAs($user)
        ->post(route('organization.companies.documents.bulk-store', $company), [
            'documents' => [
                [
                    'document_type_id' => $type->id,
                    'title' => 'Valid file',
                    'file' => companyDocumentPdf('valid.pdf'),
                ],
                [
                    'document_type_id' => $type->id,
                    'issue_date' => '2026-12-31',
                    'expiry_date' => '2026-01-01',
                    'file' => companyDocumentPdf('invalid-metadata.pdf'),
                ],
            ],
        ])
        ->assertSessionHasErrors('documents.1.expiry_date');

    expect(CompanyDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty("company-documents/{$company->id}");
});

test('metadata updates and expiry status are derived from the current date', function () {
    Storage::fake('local');
    $this->travelTo(now()->setDate(2026, 7, 14));
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext(['company_documents.update']);
    $document = storedCompanyDocument($company, $type, $user, ['expiry_date' => '2026-07-15']);

    expect($document->expiry_status)->toBe('expiring_soon');

    $this->actingAs($user)
        ->put(route('organization.companies.documents.update', [$company, $document]), [
            'document_type_id' => $type->id,
            'title' => 'Updated title',
            'document_number' => '',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2026-07-15',
            'notes' => '',
        ])
        ->assertRedirect();

    expect($document->refresh()->title)->toBe('Updated title')
        ->and($document->document_number)->toBeNull();

    $this->travel(2)->days();
    expect($document->refresh()->expiry_status)->toBe('expired');
});

test('replacing a file archives the prior version and both files remain authorized downloads', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext([
        'company_documents.update',
        'company_documents.download',
        'company_documents.view',
    ]);
    $document = storedCompanyDocument($company, $type, $user);
    $oldPath = $document->file_path;

    $this->actingAs($user)
        ->post(route('organization.companies.documents.replace', [$company, $document]), [
            'file' => companyDocumentPdf('replacement.pdf'),
        ])
        ->assertRedirect();

    $document->refresh();
    $version = CompanyDocumentVersion::query()->sole();

    expect($document->current_version)->toBe(2)
        ->and($document->file_path)->not->toBe($oldPath)
        ->and($version->file_path)->toBe($oldPath)
        ->and($version->version)->toBe(1);

    Storage::disk('local')->assertExists([$document->file_path, $version->file_path]);
    $this->get(route('organization.companies.documents.download', [$company, $document]))->assertOk();
    $this->get(route('organization.companies.documents.versions.download', [$company, $document, $version]))->assertOk();
});

test('download permission is independent and tenant document identifiers cannot cross company boundaries', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $viewer] = companyDocumentContext(['company_documents.view']);
    $document = storedCompanyDocument($company, $type, $viewer);

    $this->actingAs($viewer)
        ->get(route('organization.companies.documents.download', [$company, $document]))
        ->assertForbidden();

    $otherFixtures = makeDocumentFixtures();
    grantCompanyPermissions($viewer, $otherFixtures['company'], ['company_documents.download']);

    $this->get(route('organization.companies.documents.download', [$otherFixtures['company'], $document]))
        ->assertNotFound();
});

test('deleting a document removes current and historical physical files', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext(['company_documents.delete']);
    $document = storedCompanyDocument($company, $type, $user);
    $historicalPath = "company-documents/{$company->id}/historical.pdf";
    Storage::disk('local')->put($historicalPath, minimalPdfBytes());
    CompanyDocumentVersion::query()->create([
        'company_document_id' => $document->id,
        'company_id' => $company->id,
        'version' => 0,
        'file_path' => $historicalPath,
        'original_filename' => 'historical.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(minimalPdfBytes()),
        'checksum' => hash('sha256', minimalPdfBytes()),
    ]);

    $this->actingAs($user)
        ->delete(route('organization.companies.documents.destroy', [$company, $document]))
        ->assertRedirect();

    $this->assertSoftDeleted($document);
    Storage::disk('local')->assertMissing([$document->file_path, $historicalPath]);
});

test('permission context is restored and list and detail props are tenant scoped', function () {
    Storage::fake('local');
    ['company' => $company, 'type' => $type, 'user' => $user] = companyDocumentContext([
        'companies.view',
        'company_documents.view',
        'company_documents.download',
    ]);
    $document = storedCompanyDocument($company, $type, $user);
    $otherFixtures = makeDocumentFixtures();
    $otherUser = User::factory()->create();
    storedCompanyDocument($otherFixtures['company'], $otherFixtures['passportType'], $otherUser, ['title' => 'Other tenant secret']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($otherFixtures['company']->id);

    $permissions = app(CompanyDocumentAccess::class)->permissions($user, $company);

    expect($permissions['view'])->toBeTrue()
        ->and($registrar->getPermissionsTeamId())->toBe($otherFixtures['company']->id);

    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $company))
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/company-documents')
            ->has('documents', 1)
            ->where('documents.0.id', $document->id)
            ->where('can.view', true)
            ->where('can.upload', false));

    $this->get(route('organization.companies.show', $company))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company_documents.count', 1)
            ->has('company_documents.recent', 1)
            ->where('company_documents.recent.0.id', $document->id));

    $company->update(['name' => 'Scoped Company Documents']);

    $this->get('/organization/companies?search=Scoped%20Company%20Documents')
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies', 1)
            ->where('companies.0.can_view_documents', true));
});
