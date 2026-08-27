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
