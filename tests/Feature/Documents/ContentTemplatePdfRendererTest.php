<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Services\Documents\ContentTemplatePdfRenderer;

function createContentPdfTestCompany(string $name = 'Renderer Co'): Company
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

test('content template pdf renderer generates valid PDF with server merge fields and arabic unicode', function () {
    $company = createContentPdfTestCompany('Al Bahr Maritime LLC');
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'محمد رابيل (Mohammed Rabil)',
        'employee_no' => 'EMP-786',
        'status' => 'active',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Arabic Experience Certificate',
        'template_format' => DocumentGenerationTemplateFormat::Content,
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'content' => "Dear {{employee_name}},\n\nWe certify your employment with {{company_name}} (No: {{employee_no}}).\n\n<script>alert('xss');</script> & special characters.",
    ]);
    $template->update(['published_version_id' => $version->id]);

    $renderer = app(ContentTemplatePdfRenderer::class);
    $pdfBytes = $renderer->render($template, $version, $employee, $company->id);

    expect($pdfBytes)->not->toBeEmpty()
        ->and(str_starts_with($pdfBytes, '%PDF-'))->toBeTrue();
});
