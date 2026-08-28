<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Services\Documents\ContentTemplatePdfRenderer;
use App\Services\Documents\CustomTemplatePdfRenderer;
use App\Services\Documents\PdfOverlayTemplatePdfRenderer;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;
use App\Support\Documents\Exceptions\DocumentTemplateSourceUnavailableException;
use App\Support\Documents\PdfOverlayPlacementValidator;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

function createOverlayTestCompany(string $name = 'Overlay Corp'): Company
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

function overlayPuppeteerAvailable(): bool
{
    if (getenv('REQUIRE_PDF_RENDERER_TESTS') === 'true') {
        if (! file_exists(base_path('node_modules/puppeteer'))) {
            throw new RuntimeException('Puppeteer node module is required when REQUIRE_PDF_RENDERER_TESTS=true.');
        }

        return true;
    }

    return file_exists(base_path('node_modules/puppeteer'));
}

function minimalA4PdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage('P', [210, 297]);
    $pdf->SetFont('Helvetica', 'B', 20);
    $pdf->SetXY(20, 20);
    $pdf->Cell(0, 12, 'SOURCE CONTENT', 0, 1, 'C');
    $pdf->SetDrawColor(0, 0, 180);
    $pdf->Rect(20, 50, 170, 40);

    return $pdf->Output('S');
}

function mixedOrientationSourcePdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage('P', [210, 297]);
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, 'Page 1 - Portrait');
    $pdf->AddPage('L', [297, 210]);
    $pdf->Cell(0, 10, 'Page 2 - Landscape');

    return $pdf->Output('S');
}

/**
 * @return array<string, mixed>
 */
function overlayPlacementConfig(array $overrides = []): array
{
    return [
        'schema_version' => 1,
        'placements' => [
            array_merge([
                'id' => 'placement-001',
                'field' => '{{employee_name}}',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.1,
                'width' => 0.8,
                'height' => 0.05,
                'font_size' => 14,
                'font_weight' => 'normal',
                'text_align' => 'left',
            ], $overrides),
        ],
    ];
}

test('overlay blade renders escaped values and physical alignment', function () {
    $html = view('documents.pdf-overlay-page', [
        'page_width_mm' => 210.0,
        'page_height_mm' => 297.0,
        'embedded_font_styles' => BrowsershotEmbeddedFonts::dejaVuStyles(),
        'placements' => [
            [
                'id' => 'p1',
                'field' => '{{employee_name}}',
                'value' => 'John Doe',
                'left_mm' => 21.0,
                'top_mm' => 29.7,
                'width_mm' => 168.0,
                'height_mm' => 14.85,
                'effective_font_size' => 14.0,
                'font_weight' => 'normal',
                'text_align' => 'center',
            ],
        ],
    ])->render();

    expect($html)
        ->toContain('John Doe')
        ->toContain('21mm')
        ->toContain('29.7mm')
        ->toContain('168mm')
        ->toContain('font-size: 14pt')
        ->toContain('font-weight: normal')
        ->toContain('text-align: center')
        ->toContain('justify-content: center')
        ->toContain('dir="auto"')
        ->toContain('unicode-bidi: plaintext')
        ->toContain('white-space: nowrap')
        ->toContain('direction: ltr');
});

test('overlay blade escapes script and img onerror values', function () {
    $html = view('documents.pdf-overlay-page', [
        'page_width_mm' => 210.0,
        'page_height_mm' => 297.0,
        'embedded_font_styles' => '',
        'placements' => [
            [
                'id' => 'p1',
                'field' => '{{employee_name}}',
                'value' => "<script>alert('xss');</script><img src=x onerror=alert(1)>",
                'left_mm' => 10.0,
                'top_mm' => 10.0,
                'width_mm' => 100.0,
                'height_mm' => 10.0,
                'effective_font_size' => 12.0,
                'font_weight' => 'normal',
                'text_align' => 'left',
            ],
        ],
    ])->render();

    expect($html)
        ->not->toContain('<script>alert(')
        ->not->toContain('<img src=x onerror')
        ->toContain('&lt;script&gt;')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

test('overlay blade skips empty merge values', function () {
    $html = view('documents.pdf-overlay-page', [
        'page_width_mm' => 210.0,
        'page_height_mm' => 297.0,
        'embedded_font_styles' => '',
        'placements' => [
            [
                'id' => 'p1',
                'field' => '{{employee_name}}',
                'value' => '',
                'left_mm' => 10.0,
                'top_mm' => 10.0,
                'width_mm' => 100.0,
                'height_mm' => 10.0,
                'effective_font_size' => 12.0,
                'font_weight' => 'normal',
                'text_align' => 'left',
            ],
        ],
    ])->render();

    expect($html)->not->toContain('dir="auto"');
});

test('overlay blade keeps left and right as physical alignment values', function (string $align, string $justify) {
    $html = view('documents.pdf-overlay-page', [
        'page_width_mm' => 210.0,
        'page_height_mm' => 297.0,
        'embedded_font_styles' => '',
        'placements' => [
            [
                'id' => 'p1',
                'field' => '{{employee_name}}',
                'value' => 'محمد رابيل',
                'left_mm' => 10.0,
                'top_mm' => 10.0,
                'width_mm' => 100.0,
                'height_mm' => 10.0,
                'effective_font_size' => 12.0,
                'font_weight' => 'normal',
                'text_align' => $align,
            ],
        ],
    ])->render();

    expect($html)
        ->toContain("text-align: {$align}")
        ->toContain("justify-content: {$justify}")
        ->toContain('محمد رابيل');
})->with([
    'left' => ['left', 'flex-start'],
    'right' => ['right', 'flex-end'],
]);

test('placement validator treats null config as zero placements', function () {
    $result = PdfOverlayPlacementValidator::validate(null, 1);

    expect($result)->toBe([]);
});

test('placement validator rejects empty array config', function () {
    expect(fn () => PdfOverlayPlacementValidator::validate([], 1))
        ->toThrow(InvalidArgumentException::class, 'missing or corrupt');
});

test('placement validator rejects config missing schema_version', function () {
    expect(fn () => PdfOverlayPlacementValidator::validate(['placements' => []], 1))
        ->toThrow(InvalidArgumentException::class, 'schema version');
});

test('placement validator rejects duplicate placement ids', function () {
    $config = [
        'schema_version' => 1,
        'placements' => [
            array_merge(overlayPlacementConfig()['placements'][0], ['id' => 'same-id']),
            array_merge(overlayPlacementConfig()['placements'][0], ['id' => 'same-id', 'field' => '{{employee_no}}']),
        ],
    ];

    expect(fn () => PdfOverlayPlacementValidator::validate($config, 1))
        ->toThrow(InvalidArgumentException::class, 'duplicate ID');
});

test('placement validator accepts distinct placement ids', function () {
    $config = [
        'schema_version' => 1,
        'placements' => [
            overlayPlacementConfig()['placements'][0],
            array_merge(overlayPlacementConfig()['placements'][0], [
                'id' => 'placement-002',
                'field' => '{{employee_no}}',
            ]),
        ],
    ];

    $result = PdfOverlayPlacementValidator::validate($config, 1);

    expect($result)->toHaveCount(2)
        ->and($result[0]['id'])->toBe('placement-001')
        ->and($result[1]['id'])->toBe('placement-002');
});

test('placement validator rejects schema_version other than 1', function () {
    expect(fn () => PdfOverlayPlacementValidator::validate(['schema_version' => 2, 'placements' => []], 1))
        ->toThrow(InvalidArgumentException::class, 'schema version');
});

test('placement validator rejects a salary field key', function () {
    expect(fn () => PdfOverlayPlacementValidator::validate(overlayPlacementConfig(['field' => '{{salary_amount}}']), 1))
        ->toThrow(InvalidArgumentException::class, 'unsupported merge field');
});

test('placement validator rejects coordinates and sizes outside the page', function (array $overrides, string $message) {
    expect(fn () => PdfOverlayPlacementValidator::validate(overlayPlacementConfig($overrides), 1))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'x too large' => [['x' => 1.5], 'x coordinate'],
    'beyond right edge' => [['x' => 0.8, 'width' => 0.5], 'right page boundary'],
    'font too small' => [['font_size' => 7], 'font size'],
    'font too large' => [['font_size' => 72], 'font size'],
    'invalid page' => [['page' => 5], 'page number'],
    'invalid weight' => [['font_weight' => 'ultra-bold'], 'font weight'],
]);

test('placement validator accepts a valid schema v1 config', function () {
    $result = PdfOverlayPlacementValidator::validate(overlayPlacementConfig(), 1);

    expect($result)->toHaveCount(1)
        ->and($result[0]['field'])->toBe('{{employee_name}}')
        ->and($result[0]['font_size'])->toBe(14)
        ->and($result[0]['text_align'])->toBe('left');
});

test('placement validator rejects draft versions', function () {
    $company = createOverlayTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig(),
    ]);

    expect(fn () => PdfOverlayPlacementValidator::validateVersion($version))
        ->toThrow(InvalidArgumentException::class, 'Draft versions');
});

test('absolutePath returns a file inside the company boundary', function () {
    Storage::fake('local');
    $companyId = 999;
    $relativePath = "document-generation-templates/{$companyId}/source.pdf";
    Storage::disk('local')->put($relativePath, 'fake-pdf-content');

    expect(DocumentTemplateStorage::absolutePath($relativePath, $companyId))->toContain($relativePath);
});

test('absolutePath rejects a normal path outside the company boundary', function () {
    Storage::fake('local');

    expect(fn () => DocumentTemplateStorage::absolutePath('document-generation-templates/777/source.pdf', 999))
        ->toThrow(RuntimeException::class, 'outside the company storage boundary');
});

test('absolutePath rejects path traversal with parent segments', function () {
    Storage::fake('local');
    $companyId = 100;
    $otherCompanyId = 200;
    $otherPath = DocumentTemplateStorage::directory($otherCompanyId).'/secret.pdf';
    Storage::disk('local')->put($otherPath, 'secret-content');

    expect(fn () => DocumentTemplateStorage::absolutePath(
        DocumentTemplateStorage::directory($companyId).'/../'.$otherCompanyId.'/secret.pdf',
        $companyId,
    ))->toThrow(RuntimeException::class, 'invalid');

    expect(fn () => DocumentTemplateStorage::absolutePath(
        DocumentTemplateStorage::directory($companyId).'/sub/../../'.$otherCompanyId.'/secret.pdf',
        $companyId,
    ))->toThrow(RuntimeException::class, 'invalid');
});

test('absolutePath rejects absolute filesystem paths', function () {
    Storage::fake('local');

    expect(fn () => DocumentTemplateStorage::absolutePath('/etc/passwd', 999))
        ->toThrow(RuntimeException::class, 'invalid');
});

test('absolutePath rejects windows-style absolute paths', function () {
    Storage::fake('local');

    expect(fn () => DocumentTemplateStorage::absolutePath('C:\\Windows\\System32\\secret.pdf', 999))
        ->toThrow(RuntimeException::class, 'invalid');
});

test('absolutePath rejects a missing file', function () {
    Storage::fake('local');

    expect(fn () => DocumentTemplateStorage::absolutePath('document-generation-templates/999/missing.pdf', 999))
        ->toThrow(RuntimeException::class, 'not available');
});

test('renderer rejects a content template', function () {
    $company = createOverlayTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id))
        ->toThrow(InvalidArgumentException::class, 'cannot be rendered by PdfOverlayTemplatePdfRenderer');
});

test('renderer rejects a draft version', function () {
    $company = createOverlayTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => "document-generation-templates/{$company->id}/source.pdf",
        'source_pdf_page_count' => 1,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id))
        ->toThrow(InvalidArgumentException::class, 'Draft versions');
});

test('renderer rejects a template from another company', function () {
    Storage::fake('local');
    $company = createOverlayTestCompany();
    $otherCompany = createOverlayTestCompany('Other Corp');
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => "document-generation-templates/{$company->id}/source.pdf",
        'source_pdf_page_count' => 1,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $otherCompany->id))
        ->toThrow(InvalidArgumentException::class);
});

test('renderer throws when the source pdf file is missing', function () {
    Storage::fake('local');
    $company = createOverlayTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => "document-generation-templates/{$company->id}/missing.pdf",
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig(),
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id))
        ->toThrow(DocumentTemplateSourceUnavailableException::class);
});

test('renderer throws when source page count metadata is zero', function () {
    $company = createOverlayTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => "document-generation-templates/{$company->id}/source.pdf",
        'source_pdf_page_count' => 0,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id))
        ->toThrow(DocumentTemplateSourceUnavailableException::class);
});

test('renderer rejects a source path that belongs to another company', function () {
    Storage::fake('local');
    $companyA = createOverlayTestCompany('Renderer Co A');
    $companyB = createOverlayTestCompany('Renderer Co B');
    $foreignPath = "document-generation-templates/{$companyB->id}/secret.pdf";
    Storage::disk('local')->put($foreignPath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $foreignPath,
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig(),
    ]);
    $employee = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $companyA->id))
        ->toThrow(DocumentTemplateSourceUnavailableException::class);
});

test('renderer rejects a stored page count that does not match the source pdf', function () {
    Storage::fake('local');
    $company = createOverlayTestCompany();
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 2,
        'placement_config' => overlayPlacementConfig(),
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id))
        ->toThrow(DocumentTemplateSourceUnavailableException::class);
});

test('dispatcher routes content templates to the content renderer', function () {
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Dispatch Emp']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'content' => 'Hello {{employee_name}}',
    ]);

    $content = Mockery::mock(ContentTemplatePdfRenderer::class);
    $content->shouldReceive('render')->once()->andReturn('%PDF-content');
    $overlay = Mockery::mock(PdfOverlayTemplatePdfRenderer::class);
    $overlay->shouldNotReceive('render');

    $bytes = (new CustomTemplatePdfRenderer($content, $overlay))->render($template, $version, $employee, $company->id);

    expect($bytes)->toBe('%PDF-content');
});

test('dispatcher routes pdf overlay templates to the overlay renderer', function () {
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'Dispatch Overlay']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();

    $content = Mockery::mock(ContentTemplatePdfRenderer::class);
    $content->shouldNotReceive('render');
    $overlay = Mockery::mock(PdfOverlayTemplatePdfRenderer::class);
    $overlay->shouldReceive('render')->once()->andReturn('%PDF-overlay');

    $bytes = (new CustomTemplatePdfRenderer($content, $overlay))->render($template, $version, $employee, $company->id);

    expect($bytes)->toBe('%PDF-overlay');
});

test('renderer copies a zero-placement source pdf through the production pipeline', function () {
    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['name' => 'Passthrough Employee', 'status' => 'active']);
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);
    $path = tempnam(sys_get_temp_dir(), 'pdf_pt_');
    file_put_contents($path, $pdfBytes);

    $verify = new Fpdi;
    expect($pdfBytes)->toStartWith('%PDF-')
        ->and($verify->setSourceFile($path))->toBe(1);

    @unlink($path);
});

test('renderer preserves mixed portrait and landscape page sizes', function () {
    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $relativePath = "document-generation-templates/{$company->id}/source-2page.pdf";
    Storage::disk('local')->put($relativePath, mixedOrientationSourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 2,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);
    $path = tempnam(sys_get_temp_dir(), 'pdf_mix_');
    file_put_contents($path, $pdfBytes);

    $fpdi = new Fpdi;
    expect($fpdi->setSourceFile($path))->toBe(2);

    $s1 = $fpdi->getTemplateSize($fpdi->importPage(1));
    $s2 = $fpdi->getTemplateSize($fpdi->importPage(2));

    expect($s1['orientation'])->toBe('P')
        ->and((int) round($s1['width']))->toBe(210)
        ->and((int) round($s1['height']))->toBe(297)
        ->and($s2['orientation'])->toBe('L');

    @unlink($path);
});

test('renderer uses an archived version snapshot', function () {
    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['name' => 'Archive Test', 'status' => 'active']);
    $relativePath = "document-generation-templates/{$company->id}/source-archived.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'status' => DocumentGenerationTemplateVersionStatus::Archived,
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
        'published_at' => now()->subDay(),
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);

    expect($pdfBytes)->toStartWith('%PDF-');
});

test('renderer generates a valid overlay pdf for a normal name', function () {
    if (! overlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Mohammed Rabil',
        'employee_no' => 'EMP-100',
        'status' => 'active',
    ]);
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig(),
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);

    expect($pdfBytes)->toStartWith('%PDF-')
        ->and(hash('sha256', $pdfBytes))->not->toBe(hash('sha256', Storage::disk('local')->get($relativePath)));
});

test('renderer shrinks a long name until it fits', function () {
    if (! overlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Mohammed Abdul Rahman Al Example Very Long Employee Name',
        'status' => 'active',
    ]);
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig([
            'width' => 0.55,
            'font_size' => 24,
        ]),
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);

    expect($pdfBytes)->toStartWith('%PDF-');
});

test('renderer blocks generation when a value cannot fit at 8pt', function () {
    if (! overlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => str_repeat('ExtremelyLongUnbrokenToken', 12),
        'status' => 'active',
    ]);
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig([
            'width' => 0.08,
            'height' => 0.03,
            'font_size' => 14,
        ]),
    ]);

    expect(fn () => app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id))
        ->toThrow(DocumentTemplateLayoutException::class);
});

test('renderer generates mixed-orientation pages with placements on both', function () {
    if (! overlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Mohammed Rabil',
        'employee_no' => 'EMP-786',
        'status' => 'active',
    ]);
    $relativePath = "document-generation-templates/{$company->id}/source-2page.pdf";
    Storage::disk('local')->put($relativePath, mixedOrientationSourcePdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 2,
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [
                overlayPlacementConfig()['placements'][0],
                overlayPlacementConfig([
                    'id' => 'placement-002',
                    'field' => '{{employee_no}}',
                    'page' => 2,
                    'y' => 0.2,
                    'text_align' => 'right',
                ])['placements'][0],
            ],
        ],
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);
    $path = tempnam(sys_get_temp_dir(), 'pdf_both_');
    file_put_contents($path, $pdfBytes);

    $fpdi = new Fpdi;
    expect($fpdi->setSourceFile($path))->toBe(2);
    $s1 = $fpdi->getTemplateSize($fpdi->importPage(1));
    $s2 = $fpdi->getTemplateSize($fpdi->importPage(2));

    expect($s1['orientation'])->toBe('P')
        ->and((int) round($s1['width']))->toBe(210)
        ->and($s2['orientation'])->toBe('L');

    @unlink($path);
});

test('renderer generates arabic and mixed unicode overlay text', function () {
    if (! overlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    Storage::fake('local');
    $company = createOverlayTestCompany('شركة البحار للخدمات البحرية');
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'محمد رابيل',
        'employee_no' => 'EMP-786',
        'status' => 'active',
    ]);
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [
                overlayPlacementConfig(['id' => 'ar-name', 'text_align' => 'right'])['placements'][0],
                overlayPlacementConfig([
                    'id' => 'company',
                    'field' => '{{company_name}}',
                    'y' => 0.2,
                    'text_align' => 'right',
                ])['placements'][0],
                overlayPlacementConfig([
                    'id' => 'no',
                    'field' => '{{employee_no}}',
                    'y' => 0.3,
                    'text_align' => 'left',
                ])['placements'][0],
            ],
        ],
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);

    expect($pdfBytes)->toStartWith('%PDF-');
});

test('renderer treats html in employee values as text', function () {
    if (! overlayPuppeteerAvailable()) {
        $this->markTestSkipped('Puppeteer node module is not installed in this environment.');
    }

    Storage::fake('local');
    $company = createOverlayTestCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => "<script>alert('xss')</script>",
        'status' => 'active',
    ]);
    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, minimalA4PdfBytes());

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => overlayPlacementConfig(),
    ]);

    $pdfBytes = app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);

    expect($pdfBytes)->toStartWith('%PDF-')
        ->and($pdfBytes)->not->toContain('<script>alert');
});
