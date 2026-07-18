<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Jobs\SyncHikvisionAttendanceJob;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Services\HikvisionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

test('scheduled fetch command dispatches job when schedule is enabled', function () {
    Queue::fake();
    Carbon::setTestNow('2026-06-26 18:00:00', config('app.timezone'));

    configuredHikvisionSettings();
    hikvisionSettings()->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);

    expect(hikvisionSettings()->fresh()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);
});

test('scheduled fetch command does nothing when schedule is disabled', function () {
    Queue::fake();

    configuredHikvisionSettings();
    hikvisionSettings()->update([
        'events_fetch_schedule_enabled' => false,
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Scheduled access events fetch is disabled.');

    Queue::assertNothingPushed();
});

test('scheduled fetch command can be forced when schedule is disabled', function () {
    Queue::fake();

    configuredHikvisionSettings();
    hikvisionSettings()->update([
        'events_fetch_schedule_enabled' => false,
    ]);

    $this->artisan('hikvision:fetch-access-events', ['--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);
});

test('scheduled fetch job does not dispatch sync when fetch fails', function () {
    $hikvision = Mockery::mock(HikvisionService::class);
    $hikvision->shouldReceive('fetchScheduledAccessEvents')
        ->once()
        ->andThrow(new RuntimeException('No access controller devices found.'));

    Queue::fake();

    (new FetchHikvisionAccessEventsJob(hikvisionSettings()->id))->handle($hikvision);

    Queue::assertNotPushed(SyncHikvisionAttendanceJob::class);
});

test('scheduled fetch job dispatches attendance sync as a separate job', function () {
    $hikvision = Mockery::mock(HikvisionService::class);
    $hikvision->shouldReceive('fetchScheduledAccessEvents')
        ->once()
        ->andReturn(['fetched_count' => 0, 'message' => 'ok']);
    $hikvision->shouldNotReceive('syncAttendanceForYesterday');

    Queue::fake();

    (new FetchHikvisionAccessEventsJob(hikvisionSettings()->id))->handle($hikvision);

    Queue::assertPushed(SyncHikvisionAttendanceJob::class);
});

test('fetchScheduledAccessEvents only fetches yesterday', function () {
    Carbon::setTestNow('2026-06-26 08:50:00', config('app.timezone'));

    $hikvision = Mockery::mock(HikvisionService::class)->makePartial();
    $hikvision->shouldReceive('fetchAccessEvents')
        ->once()
        ->with(Mockery::on(
            fn ($date): bool => $date->toDateString() === '2026-06-25',
        ))
        ->andReturn(['fetched_count' => 3, 'message' => 'Fetched 3 access record(s) for 2026-06-25 (2 device, 1 mobile app).']);
    $hikvision->shouldNotReceive('syncAttendanceForYesterday');

    $result = $hikvision->fetchScheduledAccessEvents();

    expect($result['fetched_count'])->toBe(3)
        ->and($result['message'])->toContain('2026-06-25');
});

test('fetchScheduledAccessEvents does not sync attendance itself', function () {
    $hikvision = Mockery::mock(HikvisionService::class)->makePartial();
    $hikvision->shouldReceive('fetchAccessEvents')
        ->once()
        ->andReturn(['fetched_count' => 0, 'message' => 'ok']);
    $hikvision->shouldNotReceive('syncAttendanceForYesterday');

    $hikvision->fetchScheduledAccessEvents();
});

test('hikvision settings can save automatic fetch schedule', function () {
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
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '22:15',
    ])->assertRedirect()
        ->assertSessionHas('success');

    $settings = hikvisionSettings();

    expect($settings->events_fetch_schedule_enabled)->toBeTrue()
        ->and($settings->events_fetch_schedule_at)->toBe('22:15');
});
