<?php

use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;

test('browsershot embedded fonts include dejavu serif and sans faces', function () {
    $styles = BrowsershotEmbeddedFonts::dejaVuStyles();

    expect($styles)
        ->toContain("@font-face{font-family:'DejaVu Serif'")
        ->toContain("@font-face{font-family:'DejaVu Sans'")
        ->toContain('data:font/truetype;charset=utf-8;base64,');
});

test('salary declaration pdf renderer view includes embedded font styles', function () {
    $html = view('employees.salary-declaration', [
        'employee_name' => 'Test Employee',
        'nationality' => 'UAE',
        'eid_or_passport' => '123',
        'job_title' => 'Officer',
        'company_name' => 'Test Co',
        'printable' => false,
        'embedded_font_styles' => BrowsershotEmbeddedFonts::dejaVuStyles(),
    ])->render();

    expect($html)
        ->toContain('pdf-embedded-fonts')
        ->toContain('DejaVu Serif')
        ->toContain('DejaVu Sans')
        ->toContain('Employee Declaration and Acknowledgment')
        ->toContain('إقرار وموافقة الموظف');
});
