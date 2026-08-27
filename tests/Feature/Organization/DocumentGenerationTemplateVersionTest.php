<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplate;
use Database\Seeders\PermissionsSeeder;

function createVersionTestCompany(string $name = 'Version Test Co'): Company
{
    $code = strtoupper((string) fake()->unique()->lexify('??'));
    $country = Country::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'dial_code' => '+999', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'symbol' => '$', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower($code).'-'.fake()->unique()->numberBetween(1000, 9999),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('published version strictly prevents modification of renderable attributes', function () {
    $company = createVersionTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::Content,
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Original immutable text {{employee_name}}',
    ]);

    expect($version->isPublished())->toBeTrue();
    expect($version->isEditable())->toBeFalse();

    expect(fn () => $version->update(['content' => 'Modified text']))
        ->toThrow(DomainException::class, 'Cannot modify content on an immutable template version.');
});

test('draft branching enforces at most one draft per template', function () {
    $company = createVersionTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);

    $v1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Version 1 Content',
    ]);
    $template->published_version_id = $v1->id;
    $template->save();

    $action = new BranchDocumentGenerationTemplateDraft;

    // Branch first draft -> creates v2 Draft
    $draft1 = $action->handle($template);
    expect($draft1->version)->toBe(2);
    expect($draft1->status)->toBe(DocumentGenerationTemplateVersionStatus::Draft);
    expect($draft1->content)->toBe('Version 1 Content');

    // Branching again returns the EXACT same draft instance without creating v3
    $draft2 = $action->handle($template);
    expect($draft2->id)->toBe($draft1->id);
    expect($draft2->version)->toBe(2);

    expect(DocumentGenerationTemplateVersion::query()->where('document_generation_template_id', $template->id)->count())->toBe(2);
});

test('publishing a draft archives previous published versions and activates template', function () {
    $user = User::factory()->create();
    $company = createVersionTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);

    $v1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Version 1 Content',
    ]);
    $template->published_version_id = $v1->id;
    $template->save();

    $v2 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 2,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'content' => 'Version 2 Content with updates',
    ]);

    $publisher = new PublishDocumentGenerationTemplateVersion;
    $publishedV2 = $publisher->handle($v2, $user->id);

    expect($publishedV2->status)->toBe(DocumentGenerationTemplateVersionStatus::Published);
    expect($publishedV2->published_at)->not->toBeNull();

    // Check v1 is now archived
    expect($v1->fresh()->status)->toBe(DocumentGenerationTemplateVersionStatus::Archived);

    // Check parent points to v2
    $template->refresh();
    expect($template->published_version_id)->toBe($v2->id);
    expect($template->status)->toBe(DocumentGenerationTemplateStatus::Active);
    expect($template->content)->toBe('Version 2 Content with updates');
});

test('endpoint to get or create draft returns existing or branched draft', function () {
    $user = User::factory()->create();
    $company = createVersionTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);

    $v1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'V1 text',
    ]);
    $template->published_version_id = $v1->id;
    $template->save();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.draft', ['template' => $template->id]));

    $response->assertOk()
        ->assertJsonStructure([
            'draft' => ['id', 'version', 'status'],
            'placement_config',
            'template',
        ])
        ->assertJson([
            'draft' => [
                'version' => 2,
                'status' => 'draft',
            ],
        ]);
});

test('activate and deactivate manage template lifecycle explicitly', function () {
    $user = User::factory()->create();
    $company = createVersionTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $v1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
    ]);
    $template->published_version_id = $v1->id;
    $template->save();

    // Deactivate
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.deactivate', ['template' => $template->id]))
        ->assertRedirect();

    expect($template->fresh()->status)->toBe(DocumentGenerationTemplateStatus::Inactive);

    // Activate
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.activate', ['template' => $template->id]))
        ->assertRedirect();

    expect($template->fresh()->status)->toBe(DocumentGenerationTemplateStatus::Active);
});

test('version factory default creation succeeds with matching company id', function () {
    $version = DocumentGenerationTemplateVersion::factory()->create();

    expect($version)->toBeInstanceOf(DocumentGenerationTemplateVersion::class);
    expect($version->document_generation_template_id)->not->toBeNull();
    expect($version->company_id)->toBe($version->template->company_id);
});

test('version status transitions are strictly enforced', function () {
    $company = createVersionTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();

    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
    ]);

    // Published -> Draft fails
    expect(fn () => $published->update(['status' => DocumentGenerationTemplateVersionStatus::Draft]))
        ->toThrow(DomainException::class, 'Cannot transition a published version to draft.');

    // Published -> Archived succeeds
    $published->update(['status' => DocumentGenerationTemplateVersionStatus::Archived]);
    expect($published->fresh()->isArchived())->toBeTrue();

    // Archived -> Draft fails
    expect(fn () => $published->update(['status' => DocumentGenerationTemplateVersionStatus::Draft]))
        ->toThrow(DomainException::class, 'Cannot transition an archived version to draft.');

    // Archived -> Published fails
    expect(fn () => $published->update(['status' => DocumentGenerationTemplateVersionStatus::Published]))
        ->toThrow(DomainException::class, 'Cannot transition an archived version to published.');
});

test('published version protected fields cannot be modified', function () {
    $company = createVersionTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();

    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'Frozen content',
        'source_pdf_path' => 'path.pdf',
        'placement_config' => ['schema_version' => 1],
    ]);

    expect(fn () => $published->update(['source_pdf_path' => 'new-path.pdf']))
        ->toThrow(DomainException::class, 'Cannot modify source_pdf_path on an immutable template version.');
    $published->refresh();

    expect(fn () => $published->update(['placement_config' => ['schema_version' => 2]]))
        ->toThrow(DomainException::class, 'Cannot modify placement_config on an immutable template version.');
    $published->refresh();

    expect(fn () => $published->update(['version' => 99]))
        ->toThrow(DomainException::class, 'Cannot modify version on an immutable template version.');
    $published->refresh();

    expect(fn () => $published->update(['published_at' => now()->subDay()]))
        ->toThrow(DomainException::class, 'Cannot modify published_at on an immutable template version.');
    $published->refresh();

    expect(fn () => $published->update(['company_id' => 99999]))
        ->toThrow(DomainException::class, 'Cannot modify company_id on an immutable template version.');
    $published->refresh();

    expect(fn () => $published->update(['document_generation_template_id' => 99999]))
        ->toThrow(DomainException::class, 'Cannot modify document_generation_template_id on an immutable template version.');
});

test('newly created content template starts in draft with v1 draft and null published_version_id', function () {
    $user = User::factory()->create();
    $company = createVersionTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Initial Draft Content Template',
            'content' => 'Initial draft text {{employee_name}}',
        ]);

    $response->assertRedirect();

    $template = DocumentGenerationTemplate::query()->where('name', 'Initial Draft Content Template')->firstOrFail();
    expect($template->status)->toBe(DocumentGenerationTemplateStatus::Draft);
    expect($template->published_version_id)->toBeNull();

    $draft = $template->draftVersion;
    expect($draft)->not->toBeNull();
    expect($draft->version)->toBe(1);
    expect($draft->status)->toBe(DocumentGenerationTemplateVersionStatus::Draft);
    expect($draft->content)->toBe('Initial draft text {{employee_name}}');
});

test('editing draft content does not change parent content when a published version exists', function () {
    $user = User::factory()->create();
    $company = createVersionTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Published Template',
        'template_format' => DocumentGenerationTemplateFormat::Content,
        'status' => DocumentGenerationTemplateStatus::Active,
        'content' => 'V1 Published Text {{employee_name}}',
    ]);

    $v1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => 'V1 Published Text {{employee_name}}',
    ]);
    $template->published_version_id = $v1->id;
    $template->save();

    // HR edits the template
    $updater = new UpdateDocumentGenerationTemplate;
    $updater->handle($template, [
        'content' => 'V2 Unapproved Draft Text {{employee_name}}',
    ], $user);

    $template->refresh();
    // Parent content MUST remain pointing to V1 published content!
    expect($template->content)->toBe('V1 Published Text {{employee_name}}');

    // But the draft version has the new text
    $draft = $template->draftVersion;
    expect($draft)->not->toBeNull();
    expect($draft->version)->toBe(2);
    expect($draft->content)->toBe('V2 Unapproved Draft Text {{employee_name}}');
});

test('activate rejects template when published_version_id is invalid or inconsistent', function () {
    $user = User::factory()->create();
    $company = createVersionTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Draft,
        'published_version_id' => null,
    ]);

    // 1. Activation with null published_version_id fails (422)
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.activate', $template))
        ->assertStatus(422);

    // 2. Activation pointing to Draft version fails (422)
    $draft = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
    ]);
    $template->published_version_id = $draft->id;
    $template->saveQuietly();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.activate', $template))
        ->assertStatus(422);

    // 3. Activation pointing to a version from another template fails (422)
    $otherTemplate = DocumentGenerationTemplate::factory()->forCompany($company)->create();
    $otherVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($otherTemplate)->published()->create();
    $template->published_version_id = $otherVersion->id;
    $template->saveQuietly();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.activate', $template))
        ->assertStatus(422);

    // 4. Legitimate activation pointing to own Published version succeeds
    $validPublished = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 2,
    ]);
    $template->published_version_id = $validPublished->id;
    $template->saveQuietly();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.activate', $template))
        ->assertRedirect();

    expect($template->fresh()->status)->toBe(DocumentGenerationTemplateStatus::Active);
});
