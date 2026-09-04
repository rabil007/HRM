<?php

use App\Support\Documents\DocumentTemplateLayoutPreflightResult;
use App\Support\Documents\PdfOverlayLayoutPreflight;

test('font size candidates shrink from the requested size to eight points', function () {
    $preflight = new PdfOverlayLayoutPreflight;
    $candidates = $preflight->fontSizeCandidates(10);

    expect($candidates[0])->toBe(10.0)
        ->and($candidates)->toContain(8.0)
        ->and(min($candidates))->toBe(8.0)
        ->and($candidates)->toBe(array_values(array_unique($candidates)));
});

test('font size candidates never go below the renderer minimum', function () {
    $preflight = new PdfOverlayLayoutPreflight;

    expect($preflight->fontSizeCandidates(6)[0])->toBe(8.0)
        ->and($preflight->fontSizeCandidates(8))->toBe([8.0]);
});

test('first overflow exception uses the physical placement identity', function () {
    $result = new DocumentTemplateLayoutPreflightResult(
        valid: false,
        effectiveFontSizes: ['emirates_id_en' => null, 'employee_name_en' => 10.0],
        issues: [[
            'code' => PdfOverlayLayoutPreflight::ISSUE_LAYOUT_OVERFLOW,
            'severity' => 'error',
            'placement_id' => 'emirates_id_en',
            'field_key' => '{{emirates_id}}',
            'field_label' => 'Emirates ID',
            'page' => 1,
            'message' => 'Emirates ID does not fit the configured field on page 1.',
        ]],
    );

    $exception = $result->firstOverflowException();

    expect($exception)->not->toBeNull()
        ->and($exception->fieldKey)->toBe('{{emirates_id}}')
        ->and($exception->pageNumber)->toBe(1)
        ->and($exception->placementId)->toBe('emirates_id_en')
        ->and($exception->getMessage())->toBe('Emirates ID does not fit the configured field on page 1.');
});
