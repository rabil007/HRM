<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('scheduled fetch command dispatches job when schedule is enabled', function () {
    Queue::fake();

    configuredHikvisionSettings();
    HikvisionSetting::current()->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);

    expect(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);
});

test('scheduled fetch command does nothing when schedule is disabled', function () {
    Queue::fake();

    configuredHikvisionSettings();
    HikvisionSetting::current()->update([
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
    HikvisionSetting::current()->update([
        'events_fetch_schedule_enabled' => false,
    ]);

    $this->artisan('hikvision:fetch-access-events', ['--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);
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

    $settings = HikvisionSetting::current();

    expect($settings->events_fetch_schedule_enabled)->toBeTrue()
        ->and($settings->events_fetch_schedule_at)->toBe('22:15');
});
