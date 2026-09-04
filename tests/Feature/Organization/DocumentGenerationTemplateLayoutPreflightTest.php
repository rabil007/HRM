<?php

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Jobs\ValidateDocumentTemplateLayoutJob;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplateLayoutValidationFingerprint;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\PdfOverlayLayoutMeasurementClient;
use App\Support\Documents\PdfOverlayLayoutPreflight;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

test('preflight reports missing source pdf', function () {
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => null,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $result = app(PdfOverlayLayoutPreflight::class)->inspectSource(
        $template,
        $version,
        $company->id,
        allowDraft: true,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->issue['code'])->toBe('TEMPLATE_SOURCE_UNAVAILABLE');
});

test('preflight reports page count mismatch', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();
    $version->update(['source_pdf_page_count' => 9]);

    $result = app(PdfOverlayLayoutPreflight::class)->inspectSource(
        $template,
        $version->fresh(),
        $company->id,
        allowDraft: true,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->issue['code'])->toBe('TEMPLATE_SOURCE_UNAVAILABLE');
});

test('preflight reports corrupted source pdf', function () {
    ['company' => $company, 'template' => $template, 'version' => $version, 'path' => $path] = makeLayoutPreflightDraft();
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, 'not-a-pdf');

    $result = app(PdfOverlayLayoutPreflight::class)->inspectSource(
        $template,
        $version,
        $company->id,
        allowDraft: true,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->issue['code'])->toBe('TEMPLATE_SOURCE_UNAVAILABLE');
});

test('sample validation catches a narrow Emirates ID placement', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'sample',
            'placement_config' => $version->placement_config,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.status', 'queued')
        ->assertJsonPath('run.authoritative', true);

    processLayoutValidationRun((int) $response->json('run.id'), $company->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.versions.validation-runs.show', [
            'template' => $template,
            'version' => $version,
            'run' => $response->json('run.id'),
        ]))
        ->assertOk()
        ->assertJsonPath('run.valid', false)
        ->assertJsonPath('run.status', 'invalid')
        ->assertJsonPath('run.issues.0.code', 'LAYOUT_OVERFLOW')
        ->assertJsonPath('run.issues.0.field_key', '{{emirates_id}}')
        ->assertJsonPath('run.issues.0.field_label', 'Emirates ID')
        ->assertJsonPath('run.issues.0.page', 1)
        ->assertJsonPath('run.issues.0.placement_id', 'emirates_id_en');
});

test('sample validation passes a wide Emirates ID placement', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'width' => 0.8,
        'height' => 0.06,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'sample',
        ])
        ->assertAccepted()
        ->assertJsonPath('run.status', 'queued');

    processLayoutValidationRun((int) $response->json('run.id'), $company->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.versions.validation-runs.show', [
            'template' => $template,
            'version' => $version,
            'run' => $response->json('run.id'),
        ]))
        ->assertOk()
        ->assertJsonPath('run.valid', true)
        ->assertJsonPath('run.status', 'valid');
});

test('validate design does not mutate the draft', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();
    $checksum = $version->placement_config;
    $activityCount = Activity::query()->count();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'sample',
            'placement_config' => [
                'schema_version' => 2,
                'placements' => [[
                    'id' => 'employee_name_en',
                    'type' => 'field',
                    'field' => '{{employee_name}}',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.2,
                    'width' => 0.5,
                    'height' => 0.05,
                    'font_size' => 12,
                    'font_weight' => 'normal',
                    'text_align' => 'left',
                ]],
            ],
        ])
        ->assertAccepted();

    expect($version->fresh()->placement_config)->toEqual($checksum)
        ->and(Activity::query()->count())->toBe($activityCount);
});

test('validate design rejects cross-company templates', function () {
    $user = User::factory()->create();
    $companyA = makeDocumentFixtures()['company'];
    $companyB = makeDocumentFixtures()['company'];
    grantCompanyPermissions($user, $companyA, [
        'documents.templates.update',
        'documents.templates.view',
        'employees.view',
    ]);
    grantCompanyPermissions($user, $companyB, [
        'documents.templates.update',
        'documents.templates.view',
        'employees.view',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($companyB)->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory($companyB->id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, minimalPdfBytes());
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), ['mode' => 'sample'])
        ->assertNotFound();
});

test('employee preview validation requires employees view', function () {
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    grantCompanyPermissions($user, $company, ['documents.templates.update']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory($company->id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, minimalPdfBytes());
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'employee',
            'employee_id' => $employee->id,
        ])
        ->assertForbidden();
});

test('employee preview validation rejects a cross-company employee', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'width' => 0.8,
        'height' => 0.06,
    ]);
    $otherCompany = makeDocumentFixtures()['company'];
    $foreign = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'employee',
            'employee_id' => $foreign->id,
        ])
        ->assertNotFound();
});

test('publish is blocked for a narrow Emirates ID field and remains draft', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), ['mode' => 'sample'])
        ->assertAccepted();

    processLayoutValidationRun((int) $response->json('run.id'), $company->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $template,
            'version' => $version,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_INVALID')
        ->assertJsonPath('issues.0.placement_id', 'emirates_id_en')
        ->assertJsonPath('issues.0.field_label', 'Emirates ID');

    expect($version->fresh()->isDraft())->toBeTrue()
        ->and($template->fresh()->published_version_id)->toBeNull();
});

test('publish succeeds after the Emirates ID field is widened', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $config = $version->placement_config;
    $config['placements'][0]['width'] = 0.8;
    $config['placements'][0]['height'] = 0.06;
    $version->update(['placement_config' => $config]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), ['mode' => 'sample'])
        ->assertAccepted();

    processLayoutValidationRun((int) $response->json('run.id'), $company->id);

    app(PublishDocumentGenerationTemplateVersion::class)->handle($version->fresh(), $user->id);

    expect($version->fresh()->isPublished())->toBeTrue()
        ->and($template->fresh()->published_version_id)->toBe($version->id);
});

test('save draft remains allowed when layout validation would fail', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template,
            'version' => $version,
        ]), [
            'placement_config' => $version->placement_config,
            'signature_placement_config' => $version->signature_placement_config,
            'document_workflow_mode' => 'none',
            'document_signing_mode' => 'none',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($version->fresh()->isDraft())->toBeTrue();
});

test('employee preview validation works for a same-company employee', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'width' => 0.8,
        'height' => 0.06,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Mohammed Rabil T',
        'status' => 'active',
        'emirates_id' => '784-2000-1234567-1',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'employee',
            'employee_id' => $employee->id,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.validated_with.mode', 'employee')
        ->assertJsonPath('run.validated_with.employee_id', $employee->id)
        ->assertJsonPath('run.validated_with.employee_name', 'Mohammed Rabil T')
        ->assertJsonPath('run.authoritative', false);
});

test('static text overflow is reported independently of merge fields', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'id' => 'name_en',
        'field' => '{{employee_name}}',
        'width' => 0.8,
        'height' => 0.06,
    ]);

    $config = $version->placement_config;
    $config['placements'][] = [
        'id' => 'static_declaration',
        'type' => 'text',
        'text_content' => str_repeat('Declaration text that cannot fit. ', 8),
        'page' => 1,
        'x' => 0.1,
        'y' => 0.4,
        'width' => 0.05,
        'height' => 0.02,
        'font_size' => 14,
        'font_weight' => 'normal',
        'text_align' => 'left',
        'font_family' => 'sans',
        'font_color' => '#000000',
    ];

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'sample',
            'placement_config' => $config,
        ])
        ->assertAccepted();

    processLayoutValidationRun((int) $response->json('run.id'), $company->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.versions.validation-runs.show', [
            'template' => $template,
            'version' => $version,
            'run' => $response->json('run.id'),
        ]))
        ->assertOk()
        ->assertJsonPath('run.valid', false)
        ->assertJsonPath('run.issues.0.placement_id', 'static_declaration')
        ->assertJsonPath('run.issues.0.field_key', null)
        ->assertJsonPath('run.issues.0.field_label', 'Text box');
});

test('two placements of the same field are scored independently', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'id' => 'emirates_id_wide',
        'width' => 0.8,
        'height' => 0.06,
    ]);

    $config = $version->placement_config;
    $config['placements'][] = [
        'id' => 'emirates_id_narrow',
        'type' => 'field',
        'field' => '{{emirates_id}}',
        'page' => 1,
        'x' => 0.1,
        'y' => 0.3,
        'width' => 0.05,
        'height' => 0.02,
        'font_size' => 14,
        'font_weight' => 'normal',
        'text_align' => 'left',
        'font_family' => 'sans',
        'font_color' => '#000000',
    ];

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'sample',
            'placement_config' => $config,
        ])
        ->assertAccepted();

    processLayoutValidationRun((int) $response->json('run.id'), $company->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.versions.validation-runs.show', [
            'template' => $template,
            'version' => $version,
            'run' => $response->json('run.id'),
        ]))
        ->assertOk()
        ->assertJsonPath('run.valid', false)
        ->assertJsonPath('run.issues.0.placement_id', 'emirates_id_narrow')
        ->assertJsonMissingPath('run.issues.1');
});

test('preflight uses the sample Emirates ID value not the canvas label', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $result = app(PdfOverlayLayoutPreflight::class)->evaluate(
        $template,
        $version,
        $company->id,
        ['{{emirates_id}}' => '784-2000-1234567-1'],
        $version->placement_config,
        allowDraft: true,
    );

    expect($result->valid)->toBeFalse()
        ->and($result->status->value)->toBe('invalid')
        ->and($result->issues[0]['code'])->toBe('LAYOUT_OVERFLOW')
        ->and($result->issues[0]['test_value'])->toBe('784-2000-1234567-1')
        ->and($result->issues[0]['field_label'])->toBe('Emirates ID')
        ->and($result->effectiveFontSizes['emirates_id_en'])->toBeNull();
});

test('preflight shrinks a requested font until a long name fits', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'id' => 'employee_name_en',
        'field' => '{{employee_name}}',
        'width' => 0.55,
        'height' => 0.08,
        'font_size' => 24,
    ]);

    $result = app(PdfOverlayLayoutPreflight::class)->evaluate(
        $template,
        $version,
        $company->id,
        ['{{employee_name}}' => 'Mohammed Abdul Rahman Al Example Very Long Employee Name'],
        $version->placement_config,
        allowDraft: true,
    );

    expect($result->valid)->toBeTrue()
        ->and($result->effectiveFontSizes['employee_name_en'])->toBeLessThan(24)
        ->and($result->effectiveFontSizes['employee_name_en'])->toBeGreaterThanOrEqual(8);
});

test('preflight measures Arabic text with the generation font stack', function () {
    if (! layoutPreflightPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    ['company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'id' => 'employee_name_ar',
        'field' => '{{employee_name}}',
        'width' => 0.8,
        'height' => 0.08,
        'font_size' => 14,
    ]);

    $result = app(PdfOverlayLayoutPreflight::class)->evaluate(
        $template,
        $version,
        $company->id,
        ['{{employee_name}}' => 'محمد ربيل'],
        $version->placement_config,
        allowDraft: true,
    );

    expect($result->valid)->toBeTrue()
        ->and($result->effectiveFontSizes['employee_name_ar'])->not->toBeNull();
});

test('validate design rejects a version that does not belong to the template', function () {
    ['user' => $user, 'company' => $company, 'template' => $template] = makeLayoutPreflightDraft();
    $otherTemplate = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $foreignVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($otherTemplate)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $foreignVersion,
        ]), ['mode' => 'sample'])
        ->assertNotFound();
});

test('preflight evaluate reports missing source as a source issue not an engine failure', function () {
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => null,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $result = app(PdfOverlayLayoutPreflight::class)->evaluate(
        $template,
        $version,
        $company->id,
        ['{{emirates_id}}' => '784-2000-1234567-1'],
        allowDraft: true,
    );

    expect($result->status->value)->toBe('invalid')
        ->and($result->valid)->toBeFalse()
        ->and($result->issues[0]['code'])->toBe('TEMPLATE_SOURCE_UNAVAILABLE')
        ->and($result->reference)->toBeNull();
});

test('preflight evaluate reports invalid placement config with a configuration code', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $result = app(PdfOverlayLayoutPreflight::class)->evaluate(
        $template,
        $version,
        $company->id,
        ['{{emirates_id}}' => '784-2000-1234567-1'],
        ['schema_version' => 2, 'placements' => 'not-a-list'],
        allowDraft: true,
    );

    expect($result->status->value)->toBe('invalid')
        ->and($result->issues[0]['code'])->toBe('TEMPLATE_LAYOUT_CONFIGURATION_INVALID')
        ->and($result->issues[0]['placement_id'])->toBeNull()
        ->and($result->reference)->toBeNull();
});

test('measurement engine failure is unavailable not a layout issue', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $this->mock(PdfOverlayLayoutMeasurementClient::class, function ($mock) {
        $mock->shouldReceive('evaluateHtml')
            ->once()
            ->andThrow(new RuntimeException(
                'Chrome failed at /Users/ops/chrome with 784-2000-1234567-1',
                0,
                new RuntimeException('Process failed /opt/chrome --html'),
            ));
    });

    $logged = [];

    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event;
    });

    $result = app(PdfOverlayLayoutPreflight::class)->evaluate(
        $template,
        $version,
        $company->id,
        ['{{emirates_id}}' => '784-2000-1234567-1'],
        $version->placement_config,
        allowDraft: true,
        context: ['mode' => 'sample'],
    );

    expect($result->status->value)->toBe('unavailable')
        ->and($result->valid)->toBeFalse()
        ->and($result->issues)->toHaveCount(1)
        ->and($result->issues[0]['code'])->toBe('TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE')
        ->and($result->issues[0]['placement_id'])->toBeNull()
        ->and($result->issues[0]['message'])->toBe('The PDF validation engine could not complete the layout check.')
        ->and($result->reference)->toStartWith('LAY-')
        ->and($result->issues[0]['reference'])->toBe($result->reference);

    $match = collect($logged)->first(
        fn ($event): bool => ($event->message ?? null) === 'document_template_layout_preflight_failed',
    );

    expect($match)->not->toBeNull();

    $encoded = json_encode($match->context);

    expect($match->context['company_id'])->toBe($company->id)
        ->and($match->context['template_id'])->toBe($template->id)
        ->and($match->context['version_id'])->toBe($version->id)
        ->and($match->context['reference_id'])->toBe($result->reference)
        ->and($encoded)->not->toContain('784-2000-1234567-1')
        ->and($encoded)->not->toContain('/Users/ops/chrome');
});

test('validate design does not invoke chromium in the HTTP request', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();
    Queue::fake();

    $this->mock(PdfOverlayLayoutMeasurementClient::class, function ($mock) {
        $mock->shouldReceive('evaluateHtml')->never();
    });

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $template,
            'version' => $version,
        ]), [
            'mode' => 'sample',
            'placement_config' => $version->placement_config,
        ])
        ->assertAccepted()
        ->assertJsonPath('run.status', 'queued');

    Queue::assertPushed(ValidateDocumentTemplateLayoutJob::class);
    expect(DocumentTemplateLayoutValidationRun::query()->count())->toBe(1);
});

test('publish is blocked as validation unavailable when the matching run is unavailable', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

    $fingerprint = app(DocumentTemplateLayoutValidationFingerprint::class)->for(
        $template,
        $version,
        $company->id,
        $version->placement_config,
        'sample',
        null,
        true,
    );

    DocumentTemplateLayoutValidationRun::factory()->forDraft(compact('company', 'template', 'version'))->create([
        'fingerprint' => $fingerprint,
        'status' => DocumentTemplateLayoutValidationRunStatus::Unavailable,
        'authoritative' => true,
        'mode' => 'sample',
        'issues' => [[
            'code' => 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE',
            'severity' => 'error',
            'placement_id' => null,
            'field_key' => null,
            'field_label' => null,
            'page' => null,
            'message' => 'The PDF validation engine could not complete the layout check.',
            'reference' => 'LAY-01TESTUNAVAILABLE',
        ]],
        'reference' => 'LAY-01TESTUNAVAILABLE',
        'finished_at' => now(),
    ]);

    $this->mock(PdfOverlayLayoutMeasurementClient::class, function ($mock) {
        $mock->shouldReceive('evaluateHtml')->never();
    });

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $template,
            'version' => $version,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE')
        ->assertJsonPath('message', 'Layout validation could not be completed. Try again.')
        ->assertJsonPath('issues.0.placement_id', null);

    expect($version->fresh()->isDraft())->toBeTrue()
        ->and($template->fresh()->published_version_id)->toBeNull();
});
