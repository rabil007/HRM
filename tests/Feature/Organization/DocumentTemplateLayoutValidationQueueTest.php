<?php

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Jobs\ValidateDocumentTemplateLayoutJob;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Models\Employee;
use App\Support\Documents\DocumentTemplateLayoutValidationFingerprint;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\PdfOverlayLayoutMeasurementClient;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

function authoritativeFingerprint($template, $version, int $companyId): string
{
    return app(DocumentTemplateLayoutValidationFingerprint::class)->for(
        $template,
        $version,
        $companyId,
        is_array($version->placement_config) ? $version->placement_config : null,
        'sample',
        null,
        true,
    );
}

function createAuthoritativeRun(array $draft, array $overrides = []): DocumentTemplateLayoutValidationRun
{
    return DocumentTemplateLayoutValidationRun::factory()
        ->forDraft($draft)
        ->create(array_merge([
            'fingerprint' => authoritativeFingerprint($draft['template'], $draft['version'], $draft['company']->id),
            'mode' => 'sample',
            'authoritative' => true,
            'status' => DocumentTemplateLayoutValidationRunStatus::Valid,
            'issues' => [],
            'effective_font_sizes' => [],
            'finished_at' => now(),
        ], $overrides));
}

test('publish does not invoke chromium when an authoritative valid run matches', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft([
        'width' => 0.8,
        'height' => 0.06,
    ]);

    createAuthoritativeRun(compact('company', 'template', 'version'));

    $this->mock(PdfOverlayLayoutMeasurementClient::class, function ($mock) {
        $mock->shouldReceive('evaluateHtml')->never();
    });

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $template,
            'version' => $version,
        ]))
        ->assertRedirect();

    expect($version->fresh()->isPublished())->toBeTrue();
});

test('direct publish without a matching run is required', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeLayoutPreflightDraft();

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
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('queued authoritative validation blocks publish as pending', function () {
    $draft = makeLayoutPreflightDraft();
    createAuthoritativeRun($draft, [
        'status' => DocumentTemplateLayoutValidationRunStatus::Queued,
        'finished_at' => null,
    ]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_PENDING');
});

test('geometry change after a valid run requires a new validation', function () {
    $draft = makeLayoutPreflightDraft(['width' => 0.8, 'height' => 0.06]);
    createAuthoritativeRun($draft);

    $config = $draft['version']->placement_config;
    $config['placements'][0]['width'] = 0.2;
    $draft['version']->update(['placement_config' => $config]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version']->fresh(),
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('font change after a valid run requires a new validation', function () {
    $draft = makeLayoutPreflightDraft(['width' => 0.8, 'height' => 0.06]);
    createAuthoritativeRun($draft);

    $config = $draft['version']->placement_config;
    $config['placements'][0]['font_size'] = 28;
    $draft['version']->update(['placement_config' => $config]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version']->fresh(),
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('static text change after a valid run requires a new validation', function () {
    $draft = makeLayoutPreflightDraft(['width' => 0.8, 'height' => 0.06]);
    $config = $draft['version']->placement_config;
    $config['placements'][] = [
        'id' => 'static_note',
        'type' => 'text',
        'text_content' => 'Original',
        'page' => 1,
        'x' => 0.1,
        'y' => 0.5,
        'width' => 0.4,
        'height' => 0.05,
        'font_size' => 12,
        'font_weight' => 'normal',
        'text_align' => 'left',
        'font_family' => 'sans',
        'font_color' => '#000000',
    ];
    $draft['version']->update(['placement_config' => $config]);
    createAuthoritativeRun($draft);

    $config['placements'][1]['text_content'] = 'Changed copy';
    $draft['version']->update(['placement_config' => $config]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version']->fresh(),
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('source pdf content replacement invalidates a previous valid run', function () {
    $draft = makeLayoutPreflightDraft(['width' => 0.8, 'height' => 0.06]);
    createAuthoritativeRun($draft);

    Storage::disk(DocumentTemplateStorage::DISK)
        ->put($draft['path'], minimalPdfBytes().' ');

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('employee preview valid run cannot authorize publish', function () {
    $draft = makeLayoutPreflightDraft(['width' => 0.8, 'height' => 0.06]);
    $employee = Employee::factory()->forCompany($draft['company'])->create(['status' => 'active']);

    DocumentTemplateLayoutValidationRun::factory()->forDraft($draft)->create([
        'fingerprint' => str_repeat('e', 64),
        'mode' => 'employee',
        'employee_id' => $employee->id,
        'authoritative' => false,
        'status' => DocumentTemplateLayoutValidationRunStatus::Valid,
        'finished_at' => now(),
    ]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('unsaved preview valid run cannot authorize publish', function () {
    $draft = makeLayoutPreflightDraft();
    DocumentTemplateLayoutValidationRun::factory()->forDraft($draft)->create([
        'fingerprint' => str_repeat('p', 64),
        'mode' => 'sample',
        'authoritative' => false,
        'status' => DocumentTemplateLayoutValidationRunStatus::Valid,
        'finished_at' => now(),
    ]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEMPLATE_LAYOUT_VALIDATION_REQUIRED');
});

test('queue job invokes the measurement client and stores a valid result', function () {
    $draft = makeLayoutPreflightDraft(['width' => 0.8, 'height' => 0.06]);
    Queue::fake();

    $response = $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]), ['mode' => 'sample'])
        ->assertAccepted();

    $this->mock(PdfOverlayLayoutMeasurementClient::class, function ($mock) {
        $mock->shouldReceive('evaluateHtml')
            ->once()
            ->andReturn(json_encode([
                ['id' => 0, 'size' => 12, 'overflow' => false],
            ]));
    });

    processLayoutValidationRun((int) $response->json('run.id'), $draft['company']->id);

    $run = DocumentTemplateLayoutValidationRun::query()->findOrFail($response->json('run.id'));

    expect($run->status)->toBe(DocumentTemplateLayoutValidationRunStatus::Valid)
        ->and($run->authoritative)->toBeTrue();
});

test('job failed marks a processing run unavailable with a LAY reference', function () {
    $draft = makeLayoutPreflightDraft();
    $run = DocumentTemplateLayoutValidationRun::factory()->forDraft($draft)->create([
        'fingerprint' => authoritativeFingerprint($draft['template'], $draft['version'], $draft['company']->id),
        'status' => DocumentTemplateLayoutValidationRunStatus::Processing,
        'authoritative' => true,
        'mode' => 'sample',
    ]);

    (new ValidateDocumentTemplateLayoutJob($run->id, $draft['company']->id))
        ->failed(new RuntimeException('worker exploded'));

    $run->refresh();

    expect($run->status)->toBe(DocumentTemplateLayoutValidationRunStatus::Unavailable)
        ->and($run->reference)->toStartWith('LAY-')
        ->and($run->finished_at)->not->toBeNull();
});

test('save draft queues authoritative sample validation after commit', function () {
    $draft = makeLayoutPreflightDraft();
    Queue::fake();

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]), [
            'placement_config' => $draft['version']->placement_config,
            'signature_placement_config' => $draft['version']->signature_placement_config,
            'document_workflow_mode' => 'none',
            'document_signing_mode' => 'none',
        ])
        ->assertOk()
        ->assertJsonPath('layout_validation_run.status', 'queued')
        ->assertJsonPath('layout_validation_run.authoritative', true)
        ->assertJsonPath('layout_validation_run.mode', 'sample');

    Queue::assertPushed(ValidateDocumentTemplateLayoutJob::class);
});

test('matching queued runs are reused instead of dispatching duplicates', function () {
    $draft = makeLayoutPreflightDraft();
    Queue::fake();

    $first = $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]), ['mode' => 'sample'])
        ->assertAccepted()
        ->json('run.id');

    $second = $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]), ['mode' => 'sample'])
        ->assertAccepted()
        ->json('run.id');

    expect($second)->toBe($first)
        ->and(DocumentTemplateLayoutValidationRun::query()->count())->toBe(1);
});

test('employee-mode issues persist without test values', function () {
    $draft = makeLayoutPreflightDraft();
    $employee = Employee::factory()->forCompany($draft['company'])->create([
        'status' => 'active',
        'emirates_id' => '784-2000-1234567-1',
    ]);

    $response = $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $draft['company']->id])
        ->postJson(route('organization.documents.templates.versions.design.validate', [
            'template' => $draft['template'],
            'version' => $draft['version'],
        ]), [
            'mode' => 'employee',
            'employee_id' => $employee->id,
        ])
        ->assertAccepted();

    $this->mock(PdfOverlayLayoutMeasurementClient::class, function ($mock) {
        $mock->shouldReceive('evaluateHtml')
            ->once()
            ->andReturn(json_encode([
                ['id' => 0, 'size' => 14, 'overflow' => true],
            ]));
    });

    processLayoutValidationRun((int) $response->json('run.id'), $draft['company']->id);

    $run = DocumentTemplateLayoutValidationRun::query()->findOrFail($response->json('run.id'));

    expect($run->status)->toBe(DocumentTemplateLayoutValidationRunStatus::Invalid)
        ->and($run->issues[0])->not->toHaveKey('test_value');
});

test('cross-company validation run status is not found', function () {
    $draft = makeLayoutPreflightDraft();
    $run = createAuthoritativeRun($draft);
    $other = makeDocumentFixtures()['company'];
    grantCompanyPermissions($draft['user'], $other, [
        'documents.templates.update',
        'documents.templates.view',
    ]);

    $this->actingAs($draft['user'])
        ->withSession(['current_company_id' => $other->id])
        ->getJson(route('organization.documents.templates.versions.validation-runs.show', [
            'template' => $draft['template'],
            'version' => $draft['version'],
            'run' => $run,
        ]))
        ->assertNotFound();
});

test('fingerprint changes when geometry, pdf contents, or engine version change', function () {
    $draft = makeLayoutPreflightDraft();
    $service = app(DocumentTemplateLayoutValidationFingerprint::class);
    $original = $service->for(
        $draft['template'],
        $draft['version'],
        $draft['company']->id,
        $draft['version']->placement_config,
        'sample',
        null,
        true,
    );

    $config = $draft['version']->placement_config;
    $config['placements'][0]['width'] = 0.2;
    $geometry = $service->for(
        $draft['template'],
        $draft['version'],
        $draft['company']->id,
        $config,
        'sample',
        null,
        true,
    );

    Storage::disk(DocumentTemplateStorage::DISK)
        ->put($draft['path'], minimalPdfBytes().'%EOF');
    $replaced = $service->for(
        $draft['template'],
        $draft['version']->fresh(),
        $draft['company']->id,
        $draft['version']->placement_config,
        'sample',
        null,
        true,
    );

    $payload = $service->payload(
        $draft['template'],
        $draft['version'],
        $draft['company']->id,
        $draft['version']->placement_config,
        'sample',
        null,
        true,
    );
    $payload['engine_version'] = DocumentTemplateLayoutValidationFingerprint::ENGINE_VERSION + 1;

    expect($original)->not->toBe($geometry)
        ->and($original)->not->toBe($replaced)
        ->and($service->hash($payload))->not->toBe($original);
});
