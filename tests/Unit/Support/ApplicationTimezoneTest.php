<?php

use App\Services\Settings\SettingService;
use App\Support\Settings\ApplicationTimezone;
use App\Support\Settings\SettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('application timezone falls back to config when settings table is unavailable', function () {
    config(['app.timezone' => 'Asia/Dubai']);

    expect(ApplicationTimezone::identifier())->toBe('Asia/Dubai');
});

test('application timezone prefers regional settings over config', function () {
    config(['app.timezone' => 'UTC']);

    app(SettingService::class)->set(SettingKey::Timezone, 'Asia/Dubai');

    expect(ApplicationTimezone::identifier())->toBe('Asia/Dubai');
});
