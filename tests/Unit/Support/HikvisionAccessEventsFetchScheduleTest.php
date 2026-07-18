<?php

use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;

test('schedule uses configured time from hikvision settings', function () {
    hikvisionSettings()->update([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
        'enabled' => true,
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '21:30',
    ]);

    expect(HikvisionAccessEventsFetchSchedule::dispatchAt())->toBe('21:30')
        ->and(HikvisionAccessEventsFetchSchedule::isEnabled())->toBeTrue();
});

test('schedule falls back to config default when time is missing', function () {
    config(['hikvision.events_fetch_schedule_at' => '19:45']);

    hikvisionSettings()->update([
        'events_fetch_schedule_at' => null,
    ]);

    expect(HikvisionAccessEventsFetchSchedule::dispatchAt())->toBe('19:45');
});

test('schedule is disabled when integration is not configured', function () {
    hikvisionSettings()->update([
        'enabled' => false,
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    expect(HikvisionAccessEventsFetchSchedule::isEnabled())->toBeFalse();
});
