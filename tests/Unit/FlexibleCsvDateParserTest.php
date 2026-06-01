<?php

use App\Support\Imports\FlexibleCsvDateParser;

test('flexible csv date parser accepts iso dates', function () {
    expect(FlexibleCsvDateParser::parse('2024-10-18')?->toDateString())->toBe('2024-10-18');
});

test('flexible csv date parser accepts day first slash dates', function () {
    expect(FlexibleCsvDateParser::parse('18/10/2024')?->toDateString())->toBe('2024-10-18')
        ->and(FlexibleCsvDateParser::parse('31/01/2025')?->toDateString())->toBe('2025-01-31')
        ->and(FlexibleCsvDateParser::parse('25/03/2025')?->toDateString())->toBe('2025-03-25');
});

test('flexible csv date parser accepts short day first dates', function () {
    expect(FlexibleCsvDateParser::parse('3/2/25')?->toDateString())->toBe('2025-02-03')
        ->and(FlexibleCsvDateParser::parse('9/1/25')?->toDateString())->toBe('2025-01-09')
        ->and(FlexibleCsvDateParser::parse('3/1/26')?->toDateString())->toBe('2026-01-03')
        ->and(FlexibleCsvDateParser::parse('10/2/26')?->toDateString())->toBe('2026-02-10');
});

test('flexible csv date parser returns null for empty or invalid values', function () {
    expect(FlexibleCsvDateParser::parse(''))->toBeNull()
        ->and(FlexibleCsvDateParser::parse('not-a-date'))->toBeNull();
});
