<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Support\Hikvision\HikvisionEveningAccessEventsFetchSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

test('evening fetch command dispatches manual-style job for today when enabled', function () {
    Queue::fake();
    Carbon::setTestNow('2026-06-26 20:00:00', 'Asia/Dubai');

    configuredHikvisionSettings();
    HikvisionSetting::current()->update([
        'events_evening_fetch_schedule_enabled' => true,
        'events_evening_fetch_schedule_at' => '20:00',
    ]);

    $this->artisan('hikvision:fetch-todays-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job for 2026-06-26.');

    Queue::assertPushed(
        FetchHikvisionAccessEventsJob::class,
        fn (FetchHikvisionAccessEventsJob $job): bool => $job->date === '2026-06-26',
    );

    expect(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);
});

test('evening fetch command does nothing when schedule is disabled', function () {
    Queue::fake();

    configuredHikvisionSettings();
    HikvisionSetting::current()->update([
        'events_evening_fetch_schedule_enabled' => false,
    ]);

    $this->artisan('hikvision:fetch-todays-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Evening access events fetch is disabled.');

    Queue::assertNothingPushed();
});

test('evening fetch command can be forced when schedule is disabled', function () {
    Queue::fake();
    Carbon::setTestNow('2026-06-26 20:00:00', 'Asia/Dubai');

    configuredHikvisionSettings();
    HikvisionSetting::current()->update([
        'events_evening_fetch_schedule_enabled' => false,
    ]);

    $this->artisan('hikvision:fetch-todays-access-events', ['--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(
        FetchHikvisionAccessEventsJob::class,
        fn (FetchHikvisionAccessEventsJob $job): bool => $job->date === '2026-06-26',
    );
});

test('evening schedule uses configured time from hikvision settings', function () {
    HikvisionSetting::current()->update([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
        'enabled' => true,
        'events_evening_fetch_schedule_enabled' => true,
        'events_evening_fetch_schedule_at' => '20:15',
    ]);

    expect(HikvisionEveningAccessEventsFetchSchedule::dispatchAt())->toBe('20:15')
        ->and(HikvisionEveningAccessEventsFetchSchedule::isEnabled())->toBeTrue();
});

test('hikvision settings can save evening fetch schedule', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);

    test()->actingAs($user)->get(route('application.edit'));

    test()->actingAs($user)->put(route('application.hikvision.update'), [
        '_token' => csrf_token(),
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
        'events_evening_fetch_schedule_enabled' => true,
        'events_evening_fetch_schedule_at' => '20:00',
    ])->assertRedirect()
        ->assertSessionHas('success');

    $settings = HikvisionSetting::current();

    expect($settings->events_evening_fetch_schedule_enabled)->toBeTrue()
        ->and($settings->events_evening_fetch_schedule_at)->toBe('20:00');
});
