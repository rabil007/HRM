<?php

use App\Services\Settings\SettingService;
use App\Support\Settings\PlatformDateFormatter;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Carbon;

function makePlatformDateFormatter(?string $format = null): PlatformDateFormatter
{
    $settings = Mockery::mock(SettingService::class);
    $settings->shouldReceive('get')
        ->with(SettingKey::DateFormat, Mockery::any())
        ->andReturn($format ?? 'd-m-Y');

    return new PlatformDateFormatter($settings);
}

test('platform date formatter supports every catalog format', function (string $format, string $expected) {
    $formatter = makePlatformDateFormatter($format);

    expect($formatter->date('2026-05-21'))->toBe($expected)
        ->and($formatter->dateWithFormat('2026-05-21', $format))->toBe($expected);
})->with([
    ['Y-m-d', '2026-05-21'],
    ['d/m/Y', '21/05/2026'],
    ['m/d/Y', '05/21/2026'],
    ['d-m-Y', '21-05-2026'],
    ['M d, Y', 'May 21, 2026'],
]);

test('platform date formatter returns empty placeholder for null values', function () {
    $formatter = makePlatformDateFormatter('d-m-Y');

    expect($formatter->date(null))->toBe('—')
        ->and($formatter->dateTime(null))->toBe('—')
        ->and($formatter->date('', null))->toBeNull();
});

test('platform date formatter composes date and time', function () {
    $formatter = makePlatformDateFormatter('d-m-Y');
    $value = Carbon::parse('2026-05-21 14:05:00');

    expect($formatter->dateTime($value))->toBe('21-05-2026 14:05')
        ->and($formatter->dateTime($value, twelveHour: true))->toBe('21-05-2026 2:05 PM');
});

test('platform date formatter falls back for unknown configured formats', function () {
    $formatter = makePlatformDateFormatter('not-a-format');

    expect($formatter->format())->toBe('d-m-Y')
        ->and($formatter->date('2026-05-21'))->toBe('21-05-2026');
});
