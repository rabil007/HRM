<?php

use App\Models\AppSetting;
use App\Models\JobRun;
use App\Models\User;
use App\Services\Settings\SettingService;
use App\Support\Queue\JobRunRetention;
use App\Support\Settings\SettingKey;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    app(SettingService::class)->clearCache();
});

test('platform admin can view job run retention settings', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    app(SettingService::class)->setMany([
        SettingKey::JobRunCompletedRetentionDays => '12',
        SettingKey::JobRunFailedRetentionDays => '40',
        SettingKey::JobRunRunningRetentionDays => '50',
        SettingKey::JobRunDeletedRetentionDays => '8',
    ]);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('retention.completed_days', 12)
            ->where('retention.failed_days', 40)
            ->where('retention.running_days', 50)
            ->where('retention.deleted_days', 8)
            ->where('retention.activity_log.retention_reference_days', 365)
            ->where('retention.activity_log.automatic_cleanup', false)
            ->where('can.platform_update', false),
        );
});

test('platform admin can update job run retention and the settings cache refreshes', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $settings = app(SettingService::class);
    $settings->setMany([
        SettingKey::JobRunCompletedRetentionDays => '30',
        SettingKey::JobRunFailedRetentionDays => '90',
        SettingKey::JobRunRunningRetentionDays => '90',
        SettingKey::JobRunDeletedRetentionDays => '30',
    ]);

    expect(app(JobRunRetention::class)->completedDays())->toBe(30);

    $this->actingAs($user)
        ->put(route('application.retention.update'), [
            'completed_days' => 15,
            'failed_days' => 120,
            'running_days' => 80,
            'deleted_days' => 9,
            'company_id' => 999,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(app(JobRunRetention::class)->completedDays())->toBe(15)
        ->and(app(JobRunRetention::class)->failedDays())->toBe(120)
        ->and(app(JobRunRetention::class)->runningDays())->toBe(80)
        ->and(app(JobRunRetention::class)->deletedDays())->toBe(9)
        ->and(setting(SettingKey::JobRunCompletedRetentionDays))->toBe('15')
        ->and(AppSetting::query()->where('key', SettingKey::JobRunCompletedRetentionDays)->value('value'))->toBe('15')
        ->and(AppSetting::query()->where('key', 'company_id')->exists())->toBeFalse()
        ->and(Schema::hasColumn('app_settings', 'company_id'))->toBeFalse();
});

test('unauthorized users cannot update platform job run retention', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->put(route('application.retention.update'), [
            'completed_days' => 10,
            'failed_days' => 20,
            'running_days' => 20,
            'deleted_days' => 10,
        ])
        ->assertForbidden();

    expect(AppSetting::query()->where('key', SettingKey::JobRunCompletedRetentionDays)->exists())->toBeFalse();
});

test('job run retention rejects values outside 1 to 3650 and ignores company scope', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->from(route('application.edit'))
        ->put(route('application.retention.update'), [
            'completed_days' => 0,
            'failed_days' => 90,
            'running_days' => 90,
            'deleted_days' => 30,
            'company_id' => 999,
        ])
        ->assertRedirect(route('application.edit'))
        ->assertSessionHasErrors('completed_days');

    $this->actingAs($user)
        ->from(route('application.edit'))
        ->put(route('application.retention.update'), [
            'completed_days' => 30,
            'failed_days' => 3651,
            'running_days' => 90,
            'deleted_days' => 30,
        ])
        ->assertSessionHasErrors('failed_days');

    expect(AppSetting::query()->where('key', SettingKey::JobRunCompletedRetentionDays)->exists())->toBeFalse()
        ->and(AppSetting::query()->where('key', '999')->exists())->toBeFalse();
});

test('changing job run retention writes a platform activity log and pruning uses the saved values', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->put(route('application.retention.update'), [
            'completed_days' => 7,
            'failed_days' => 14,
            'running_days' => 10,
            'deleted_days' => 5,
        ])
        ->assertRedirect();

    $activity = Activity::query()
        ->where('description', 'updated platform job run retention settings')
        ->where('log_name', 'platform')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->company_id)->toBeNull()
        ->and($activity->properties['scope'])->toBe('platform')
        ->and($activity->properties['keys'])->toContain(SettingKey::JobRunCompletedRetentionDays)
        ->and($activity->properties['old'][SettingKey::JobRunCompletedRetentionDays])->toBe('30')
        ->and($activity->properties['new'][SettingKey::JobRunCompletedRetentionDays])->toBe('7');

    $completed = JobRun::query()->create([
        'type' => JobRun::TYPE_QUEUE,
        'name' => 'old-completed',
        'status' => JobRun::STATUS_COMPLETED,
        'trigger' => JobRun::TRIGGER_SYSTEM,
        'started_at' => now()->subDays(8),
        'finished_at' => now()->subDays(8),
        'duration_ms' => 1000,
    ]);
    JobRun::query()->whereKey($completed->id)->update([
        'created_at' => now()->subDays(8),
        'updated_at' => now()->subDays(8),
    ]);

    $recent = JobRun::query()->create([
        'type' => JobRun::TYPE_QUEUE,
        'name' => 'recent-completed',
        'status' => JobRun::STATUS_COMPLETED,
        'trigger' => JobRun::TRIGGER_SYSTEM,
        'started_at' => now()->subDays(2),
        'finished_at' => now()->subDays(2),
        'duration_ms' => 1000,
    ]);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($completed->id))->toBeNull()
        ->and(JobRun::query()->find($recent->id))->not->toBeNull();

    Carbon::setTestNow();
    app(SettingService::class)->clearCache();
});

test('activity log cleanup remains unscheduled', function () {
    $activityCleanup = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'activitylog:clean'));

    expect($activityCleanup)->toBeNull();
});
