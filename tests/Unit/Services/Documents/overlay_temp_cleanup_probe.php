<?php

declare(strict_types=1);

use App\Enums\DocumentGenerationTemplateFormat;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Services\Documents\PdfOverlayTemplatePdfRenderer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use setasign\Fpdi\Fpdi;

require __DIR__.'/../../../vendor/autoload.php';

$mockShot = Mockery::mock();
$mockShot->shouldReceive('evaluate')->andReturn(json_encode([
    ['id' => 0, 'size' => 14, 'overflow' => false],
]));
$mockShot->shouldReceive('save')->andReturnUsing(function (string $path): void {
    file_put_contents($path, '%PDF-partial');

    throw new RuntimeException('Simulated Browsershot save failure');
});
$mockShot->shouldIgnoreMissing()->andReturnSelf();

Mockery::mock('alias:Spatie\Browsershot\Browsershot')
    ->shouldReceive('html')
    ->andReturn($mockShot);

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$kernel->call('migrate', ['--force' => true]);

$before = glob(sys_get_temp_dir().'/pdf_overlay_*.pdf') ?: [];

try {
    $code = strtoupper(substr(uniqid('', true), -2));
    $country = Country::query()->create([
        'code' => $code,
        'name' => "Probe {$code}",
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code.'C',
        'name' => "Probe {$code} Currency",
        'symbol' => '$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Overlay Temp Probe Co',
        'slug' => 'overlay-temp-probe-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Storage::fake('local');

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Probe Employee',
        'status' => 'active',
    ]);

    $pdf = new Fpdi;
    $pdf->AddPage('P', [210, 297]);
    $pdf->SetFont('Helvetica', 'B', 20);
    $pdf->Cell(0, 12, 'SOURCE CONTENT');

    $relativePath = "document-generation-templates/{$company->id}/source.pdf";
    Storage::disk('local')->put($relativePath, $pdf->Output('S'));

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $relativePath,
        'source_pdf_page_count' => 1,
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [[
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
            ]],
        ],
    ]);

    app(PdfOverlayTemplatePdfRenderer::class)->render($template, $version, $employee, $company->id);

    fwrite(STDERR, "Expected render to throw\n");
    exit(1);
} catch (RuntimeException $exception) {
    if (! str_contains($exception->getMessage(), 'Simulated Browsershot save failure')) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}

$after = glob(sys_get_temp_dir().'/pdf_overlay_*.pdf') ?: [];

if ($after !== $before) {
    fwrite(STDERR, "Overlay temp files were not cleaned up\n");
    exit(1);
}

echo "ok\n";
