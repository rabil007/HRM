<?php

use App\Mail\CompanyDocumentExpiryAlertMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentExpiryNotificationRecipient;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\CompanyDocumentVersion;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\CompanyDocumentExpiryAlertService;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function branchDocumentPdf(string $name = 'branch-license.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, minimalPdfBytes());
}

/** @return array{company: Company, branch: Branch, type: DocumentType, user: User} */
function branchDocumentContext(array $permissions): array
{
    $fixtures = makeDocumentFixtures();
    $company = $fixtures['company'];
    $branch = $fixtures['branch'];
    $user = User::factory()->create(['company_id' => $company->id]);
    grantCompanyPermissions($user, $company, $permissions);

    return [
        'company' => $company,
        'branch' => $branch,
        'type' => $fixtures['passportType'],
        'user' => $user,
    ];
}

function storedBranchDocument(
    Company $company,
    Branch $branch,
    DocumentType $type,
    User $user,
    array $overrides = [],
): CompanyDocument {
    $path = "company-documents/{$company->id}/branches/{$branch->id}/stored.pdf";
    Storage::disk('local')->put($path, minimalPdfBytes());

    return CompanyDocument::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'document_type_id' => $type->id,
        'title' => 'Office Lease',
        'document_number' => 'OL-100',
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
    $branch = $fixtures['branch'];

    $this->get(route('organization.branches.documents.index', $branch))
        ->assertRedirect(route('login'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organization.branches.documents.index', $branch))
        ->assertNotFound();
});

test('active members without the target branch or document permission receive forbidden and inactive members receive not found', function () {
    $fixtures = makeDocumentFixtures();
    $branch = $fixtures['branch'];
    $user = User::factory()->create();

    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Member with neither permission
    $this->actingAs($user)
        ->get(route('organization.branches.documents.index', $branch))
        ->assertForbidden();

    // Member with branches.view but no company_documents.view
    grantCompanyPermissions($user, $fixtures['company'], ['branches.view']);
    $this->actingAs($user)
        ->get(route('organization.branches.documents.index', $branch))
        ->assertForbidden();

    // Inactive member receives 404
    DB::table('company_user')->where('user_id', $user->id)->update(['status' => 'inactive']);
    $this->get(route('organization.branches.documents.index', $branch))
        ->assertNotFound();
});

test('branch from another tenant returns not found', function () {
    $fixtures = makeDocumentFixtures();
    $otherFixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($user, $fixtures['company'], ['branches.view', 'company_documents.view']);

    // Attempting to access another company's branch
    $this->actingAs($user)
        ->get(route('organization.branches.documents.index', $otherFixtures['branch']))
        ->assertNotFound();
});

test('document belonging to another company returns not found', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
        'company_documents.download',
    ]);

    $otherFixtures = makeDocumentFixtures();
    $otherUser = User::factory()->create(['company_id' => $otherFixtures['company']->id]);
    $otherDoc = storedBranchDocument($otherFixtures['company'], $otherFixtures['branch'], $otherFixtures['passportType'], $otherUser);

    // Requesting other company's document through our branch returns 404
    $this->actingAs($user)
        ->get(route('organization.branches.documents.download', [$branch, $otherDoc]))
        ->assertNotFound();
});

test('document belonging to another branch of the same company returns not found', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch1, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
        'company_documents.download',
        'company_documents.update',
        'company_documents.delete',
    ]);

    $branch2 = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Dubai Branch',
        'code' => 'DXB',
        'status' => 'active',
        'is_headquarters' => false,
    ]);

    $docInBranch2 = storedBranchDocument($company, $branch2, $type, $user, ['title' => 'Branch 2 Document']);

    // Accessing branch 2 document via branch 1 route must return 404
    $this->actingAs($user)
        ->get(route('organization.branches.documents.download', [$branch1, $docInBranch2]))
        ->assertNotFound();

    $this->get(route('organization.branches.documents.preview', [$branch1, $docInBranch2]))
        ->assertNotFound();

    $this->put(route('organization.branches.documents.update', [$branch1, $docInBranch2]), [
        'document_type_id' => $type->id,
        'title' => 'Hacked',
    ])->assertNotFound();

    $this->delete(route('organization.branches.documents.destroy', [$branch1, $docInBranch2]))
        ->assertNotFound();

    $this->post(route('organization.branches.documents.replace', [$branch1, $docInBranch2]), [
        'file' => branchDocumentPdf(),
    ])->assertNotFound();
});

test('branch documents listing only returns documents for that branch and excludes company and other branch documents', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch1, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    $branch2 = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Branch 2',
        'code' => 'B2',
        'status' => 'active',
    ]);

    // Doc for Branch 1
    $branch1Doc = storedBranchDocument($company, $branch1, $type, $user, ['title' => 'Branch 1 Unique Title']);

    // Doc for Branch 2
    storedBranchDocument($company, $branch2, $type, $user, ['title' => 'Branch 2 Document']);

    // Company-level doc (branch_id = null)
    CompanyDocument::query()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'document_type_id' => $type->id,
        'title' => 'Company-Level Document',
        'file_path' => "company-documents/{$company->id}/comp.pdf",
        'original_filename' => 'comp.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'abc',
        'current_version' => 1,
        'uploaded_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.branches.documents.index', $branch1))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/branch-documents')
            ->has('documents', 1)
            ->where('documents.0.id', $branch1Doc->id)
            ->where('documents.0.title', 'Branch 1 Unique Title')
            ->where('summary.total', 1));
});

test('branch document search, type filter, and expiry filters work and summary counts are branch scoped', function () {
    Storage::fake('local');
    $this->travelTo(now()->setDate(2026, 7, 14));
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    $type2 = DocumentType::query()->create(['title' => 'Safety Certificate', 'is_active' => true]);

    $docValid = storedBranchDocument($company, $branch, $type, $user, [
        'title' => 'Valid Office Contract',
        'document_number' => 'DOC-AAA',
        'expiry_date' => '2026-12-31',
    ]);

    $docExpiring = storedBranchDocument($company, $branch, $type2, $user, [
        'title' => 'Civil Defence Cert',
        'document_number' => 'DOC-BBB',
        'expiry_date' => '2026-07-20', // within 30 days
    ]);

    $docExpired = storedBranchDocument($company, $branch, $type, $user, [
        'title' => 'Expired Permit',
        'document_number' => 'DOC-CCC',
        'expiry_date' => '2026-07-01',
    ]);

    // Check summary counts
    $this->actingAs($user)
        ->get(route('organization.branches.documents.index', $branch))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 3)
            ->where('summary.valid', 1)
            ->where('summary.expiring_soon', 1)
            ->where('summary.expired', 1));

    // Search
    $this->get(route('organization.branches.documents.index', [$branch, 'search' => 'Civil Defence']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents', 1)
            ->where('documents.0.id', $docExpiring->id));

    // Type filter
    $this->get(route('organization.branches.documents.index', [$branch, 'document_type' => $type2->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents', 1)
            ->where('documents.0.id', $docExpiring->id));

    // Expiry status filter
    $this->get(route('organization.branches.documents.index', [$branch, 'expiry_status' => 'expired']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents', 1)
            ->where('documents.0.id', $docExpired->id));
});

test('existing company documents page, company detail count, and recent list exclude branch documents', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'companies.view',
        'branches.view',
        'company_documents.view',
        'company_documents.download',
    ]);

    // Create 1 company document
    $companyDoc = CompanyDocument::query()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'document_type_id' => $type->id,
        'title' => 'Company Master Document',
        'file_path' => "company-documents/{$company->id}/master.pdf",
        'original_filename' => 'master.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'master',
        'current_version' => 1,
        'uploaded_by' => $user->id,
    ]);

    // Create 2 branch documents
    storedBranchDocument($company, $branch, $type, $user, ['title' => 'Branch Doc 1']);
    storedBranchDocument($company, $branch, $type, $user, ['title' => 'Branch Doc 2']);

    // 1. Company Documents page must only show the 1 company document
    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/company-documents')
            ->has('documents', 1)
            ->where('documents.0.id', $companyDoc->id)
            ->where('summary.total', 1));

    // 2. Company Detail page document count and recent documents must exclude branch documents
    $this->get(route('organization.companies.show', $company))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company_documents.count', 1)
            ->has('company_documents.recent', 1)
            ->where('company_documents.recent.0.id', $companyDoc->id));
});

test('single branch document upload succeeds with trusted company_id and branch_id on private path', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.upload',
        'company_documents.view',
    ]);

    $file = branchDocumentPdf('trade-lic.pdf');

    $this->actingAs($user)
        ->post(route('organization.branches.documents.store', $branch), [
            'document_type_id' => $type->id,
            'title' => 'Branch Trade License',
            'document_number' => 'BTL-999',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2026-12-31',
            'notes' => 'Branch office renewal',
            'file' => $file,
            // Malicious client inputs that must be ignored
            'company_id' => 99999,
            'branch_id' => 99999,
        ])
        ->assertRedirect();

    $document = CompanyDocument::query()->sole();

    expect($document->company_id)->toBe($company->id)
        ->and($document->branch_id)->toBe($branch->id)
        ->and($document->title)->toBe('Branch Trade License')
        ->and($document->document_number)->toBe('BTL-999')
        ->and($document->current_version)->toBe(1)
        ->and($document->file_path)->toStartWith("company-documents/{$company->id}/branches/{$branch->id}/");

    Storage::disk('local')->assertExists($document->file_path);
});

test('invalid file and oversized file are rejected during branch document upload', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.upload',
    ]);

    // Executable disguised as pdf
    $invalidFile = UploadedFile::fake()->createWithContent('malicious.pdf', '<?php echo "evil"; ?>');

    $this->actingAs($user)
        ->post(route('organization.branches.documents.store', $branch), [
            'document_type_id' => $type->id,
            'title' => 'Disguised PHP',
            'file' => $invalidFile,
        ])
        ->assertSessionHasErrors('file');

    // Oversized file (> 20MB)
    $oversized = UploadedFile::fake()->create('huge.pdf', 21 * 1024, 'application/pdf');

    $this->post(route('organization.branches.documents.store', $branch), [
        'document_type_id' => $type->id,
        'title' => 'Too big',
        'file' => $oversized,
    ])->assertSessionHasErrors('file');

    expect(CompanyDocument::query()->count())->toBe(0);
});

test('branch bulk upload stores multiple documents and atomic rollback on error', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.upload',
    ]);

    // Valid bulk upload
    $this->actingAs($user)
        ->post(route('organization.branches.documents.bulk-store', $branch), [
            'documents' => [
                [
                    'document_type_id' => $type->id,
                    'title' => 'Doc 1',
                    'file' => branchDocumentPdf('doc1.pdf'),
                ],
                [
                    'document_type_id' => $type->id,
                    'title' => 'Doc 2',
                    'file' => branchDocumentPdf('doc2.pdf'),
                ],
            ],
        ])
        ->assertRedirect();

    expect(CompanyDocument::query()->where('branch_id', $branch->id)->count())->toBe(2);

    // Atomic rollback when one item in batch fails
    CompanyDocument::query()->forceDelete();
    Storage::disk('local')->deleteDirectory("company-documents/{$company->id}/branches/{$branch->id}");

    $this->post(route('organization.branches.documents.bulk-store', $branch), [
        'documents' => [
            [
                'document_type_id' => $type->id,
                'title' => 'Valid Row',
                'file' => branchDocumentPdf('valid.pdf'),
            ],
            [
                'document_type_id' => $type->id,
                'title' => 'Invalid Date Row',
                'issue_date' => '2026-05-01',
                'expiry_date' => '2026-01-01', // before issue_date
                'file' => branchDocumentPdf('invalid.pdf'),
            ],
        ],
    ])->assertSessionHasErrors('documents.1.expiry_date');

    expect(CompanyDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty("company-documents/{$company->id}/branches/{$branch->id}");
});

test('branch document metadata updates and replace file creates historical version', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.update',
        'company_documents.download',
        'company_documents.view',
    ]);

    $document = storedBranchDocument($company, $branch, $type, $user);
    $oldPath = $document->file_path;

    // 1. Metadata update
    $this->actingAs($user)
        ->put(route('organization.branches.documents.update', [$branch, $document]), [
            'document_type_id' => $type->id,
            'title' => 'Updated Branch Title',
            'document_number' => 'NUM-NEW',
            'issue_date' => '2026-02-01',
            'expiry_date' => '2026-11-30',
            'notes' => 'Updated notes',
        ])
        ->assertRedirect();

    expect($document->refresh()->title)->toBe('Updated Branch Title')
        ->and($document->document_number)->toBe('NUM-NEW');

    // 2. File replace
    $this->post(route('organization.branches.documents.replace', [$branch, $document]), [
        'file' => branchDocumentPdf('v2.pdf'),
    ])->assertRedirect();

    $document->refresh();
    $version = CompanyDocumentVersion::query()->sole();

    expect($document->current_version)->toBe(2)
        ->and($document->file_path)->not->toBe($oldPath)
        ->and($document->file_path)->toStartWith("company-documents/{$company->id}/branches/{$branch->id}/")
        ->and($version->file_path)->toBe($oldPath)
        ->and($version->version)->toBe(1)
        ->and($version->company_id)->toBe($company->id);

    Storage::disk('local')->assertExists([$document->file_path, $version->file_path]);

    // 3. Download current & historical version
    $this->get(route('organization.branches.documents.download', [$branch, $document]))->assertOk();
    $this->get(route('organization.branches.documents.versions.download', [$branch, $document, $version]))->assertOk();

    // 4. Versions JSON endpoint
    $this->getJson(route('organization.branches.documents.versions.index', [$branch, $document]))
        ->assertOk()
        ->assertJsonCount(1, 'versions')
        ->assertJsonPath('versions.0.version', 1);
});

test('branch document delete removes physical files and soft deletes the record', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.delete',
    ]);

    $document = storedBranchDocument($company, $branch, $type, $user);
    $historicalPath = "company-documents/{$company->id}/branches/{$branch->id}/hist.pdf";
    Storage::disk('local')->put($historicalPath, minimalPdfBytes());

    CompanyDocumentVersion::query()->create([
        'company_document_id' => $document->id,
        'company_id' => $company->id,
        'version' => 0,
        'file_path' => $historicalPath,
        'original_filename' => 'hist.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'hist',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.branches.documents.destroy', [$branch, $document]))
        ->assertRedirect();

    $this->assertSoftDeleted($document);
    Storage::disk('local')->assertMissing([$document->file_path, $historicalPath]);
});

test('expiry alert service discovers expiring branch documents and identifies branch scope', function () {
    Mail::fake();
    $this->travelTo(now()->setDate(2026, 7, 14));
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    // Set notification recipient
    $recipient = User::factory()->create(['company_id' => $company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $recipient->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
    $setting = CompanyDocumentExpiryNotificationSetting::query()->create([
        'company_id' => $company->id,
        'enabled' => true,
    ]);
    CompanyDocumentExpiryNotificationRecipient::query()->create([
        'setting_id' => $setting->id,
        'user_id' => $recipient->id,
        'type' => 'to',
    ]);

    // Expiring branch document
    storedBranchDocument($company, $branch, $type, $user, [
        'title' => 'Branch Fire License',
        'expiry_date' => '2026-07-20', // expiring in 6 days
    ]);

    // Expiring company document
    CompanyDocument::query()->create([
        'company_id' => $company->id,
        'branch_id' => null,
        'document_type_id' => $type->id,
        'title' => 'Company Establishment Card',
        'file_path' => "company-documents/{$company->id}/est.pdf",
        'original_filename' => 'est.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'est',
        'current_version' => 1,
        'uploaded_by' => $user->id,
        'expiry_date' => '2026-07-22',
    ]);

    $service = app(CompanyDocumentExpiryAlertService::class);
    expect($service->hasPendingDocuments($company->id))->toBeTrue();

    $service->sendForCompany($company->id);

    Mail::assertSent(CompanyDocumentExpiryAlertMail::class, function (CompanyDocumentExpiryAlertMail $mail) use ($branch) {
        $rows = $mail->rows;
        expect($rows)->toHaveCount(2);

        $branchRow = collect($rows)->firstWhere('document_name', 'Branch Fire License');
        expect($branchRow)->not->toBeNull()
            ->and($branchRow['scope'])->toBe($branch->name)
            ->and($branchRow['view_url'])->toContain("organization/branches/{$branch->id}/documents");

        $companyRow = collect($rows)->firstWhere('document_name', 'Company Establishment Card');
        expect($companyRow)->not->toBeNull()
            ->and($companyRow['scope'])->toBe('Company');

        return true;
    });
});

test('branch details and branches list include can_view_documents and documents_summary', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    storedBranchDocument($company, $branch, $type, $user);

    $this->actingAs($user)
        ->get(route('organization.branches.show', $branch))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/branch')
            ->where('can_view_documents', true)
            ->where('documents_summary.total', 1));

    $this->get(route('organization.branches'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/branches')
            ->where('can_view_documents', true)
            ->where('branches.0.can_view_documents', true));
});

test('alert ledger prevents duplicate expiry notifications for branch documents', function () {
    Mail::fake();
    $this->travelTo(now()->setDate(2026, 7, 14));
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    $recipient = User::factory()->create(['company_id' => $company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $recipient->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
    $setting = CompanyDocumentExpiryNotificationSetting::query()->create([
        'company_id' => $company->id,
        'enabled' => true,
    ]);
    CompanyDocumentExpiryNotificationRecipient::query()->create([
        'setting_id' => $setting->id,
        'user_id' => $recipient->id,
        'type' => 'to',
    ]);

    $doc = storedBranchDocument($company, $branch, $type, $user, [
        'title' => 'Expiring Branch Permit',
        'expiry_date' => '2026-07-25',
    ]);

    $service = app(CompanyDocumentExpiryAlertService::class);
    expect($service->hasPendingDocuments($company->id))->toBeTrue();

    // First send
    $service->sendForCompany($company->id);
    Mail::assertSentCount(1);

    // Assert alert record exists in ledger
    expect(DB::table('company_document_expiry_alerts')
        ->where('company_document_id', $doc->id)
        ->where('company_id', $company->id)
        ->exists())->toBeTrue();

    // Second send should not send again because ledger has record for current expiry date
    expect($service->hasPendingDocuments($company->id))->toBeFalse();
    $service->sendForCompany($company->id);
    Mail::assertSentCount(1);
});

test('view permission alone does not grant branch document download and download is separately enforced', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $viewer] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    $document = storedBranchDocument($company, $branch, $type, $viewer);

    // Can view list
    $this->actingAs($viewer)
        ->get(route('organization.branches.documents.index', $branch))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.view', true)
            ->where('can.download', false));

    // Cannot download
    $this->get(route('organization.branches.documents.download', [$branch, $document]))
        ->assertForbidden();

    // Cannot preview (preview requires download permission)
    $this->get(route('organization.branches.documents.preview', [$branch, $document]))
        ->assertForbidden();

    // When granted download permission, download succeeds
    grantCompanyPermissions($viewer, $company, ['branches.view', 'company_documents.download']);
    $this->get(route('organization.branches.documents.download', [$branch, $document]))
        ->assertOk();
});

test('permission team context is restored after branch authorization checks', function () {
    Storage::fake('local');
    ['company' => $company, 'branch' => $branch, 'type' => $type, 'user' => $user] = branchDocumentContext([
        'branches.view',
        'company_documents.view',
    ]);

    $otherFixtures = makeDocumentFixtures();
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($otherFixtures['company']->id);

    $access = app(CompanyDocumentAccess::class);
    $permissions = $access->permissionsForBranch($user, $company, $branch);

    expect($permissions['view'])->toBeTrue()
        ->and($registrar->getPermissionsTeamId())->toBe($otherFixtures['company']->id);
});
