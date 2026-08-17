<?php

use App\Models\User;
use App\Support\Logging\ApplicationLogReader;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

test('guests cannot view application logs', function () {
    $this->get('/log')->assertRedirect(route('login'));
});

test('authenticated users without platform access cannot view application logs', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(
        storage_path('logs/laravel.log'),
        "[2026-06-10 10:00:00] local.ERROR: File upload failed. {\"reason\":\"test\"}\n",
    );

    $this->get('/log')->assertForbidden();
    $this->get(route('log.export'))->assertForbidden();
    $this->from('/log')->delete('/log', ['scope' => 'all'])->assertForbidden();
});

test('tenant admins without platform access cannot view or clear logs', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'roles.update',
        'settings.application.update',
    ]);

    $this->actingAs($user)->get('/log')->assertForbidden();
    $this->actingAs($user)->delete('/log', ['scope' => 'current', 'file' => 'laravel.log'])->assertForbidden();
});

test('platform viewers can view application logs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(
        storage_path('logs/laravel.log'),
        "[2026-06-10 10:00:00] local.ERROR: File upload failed. {\"reason\":\"test\"}\n",
    );

    $this->get('/log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('log')
            ->has('entries', 1)
            ->where('entries.0.message', 'File upload failed.')
            ->where('entries.0.level', 'error')
            ->where('can.manage', false));
});

test('application log viewer rejects invalid log file names', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/laravel.log'), "[2026-06-10 10:00:00] local.INFO: ok\n");

    $this->get('/log?file=../.env')->assertNotFound();
});

test('platform viewers cannot clear application logs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    $path = storage_path('logs/laravel.log');
    File::put($path, "[2026-06-10 10:00:00] local.ERROR: old entry\n");

    $this->from('/log')
        ->delete('/log', [
            'scope' => 'current',
            'file' => 'laravel.log',
        ])
        ->assertForbidden();

    expect(File::get($path))->toContain('old entry');
});

test('platform managers can clear the selected application log file', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    $path = storage_path('logs/laravel.log');
    File::put($path, "[2026-06-10 10:00:00] local.ERROR: old entry\n");

    $this->from('/log')
        ->delete('/log', [
            'scope' => 'current',
            'file' => 'laravel.log',
        ])
        ->assertRedirect(route('log', ['file' => 'laravel.log']))
        ->assertSessionHas('success');

    expect(File::get($path))->toBe('');

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Cleared application logs')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe($user->id)
        ->and($activity->properties->get('action'))->toBe('platform.logs.clear_file')
        ->and($activity->properties->get('file'))->toBe('laravel.log');
});

test('platform managers can clear all application log files', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/laravel.log'), "[2026-06-10 10:00:00] local.ERROR: one\n");
    File::put(storage_path('logs/laravel-2026-06-09.log'), "[2026-06-09 10:00:00] local.ERROR: two\n");

    $this->from('/log')
        ->delete('/log', ['scope' => 'all'])
        ->assertRedirect(route('log'))
        ->assertSessionHas('success');

    expect(File::get(storage_path('logs/laravel.log')))->toBe('')
        ->and(File::get(storage_path('logs/laravel-2026-06-09.log')))->toBe('');

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->latest('id')
        ->first();

    expect($activity?->properties->get('action'))->toBe('platform.logs.clear_all');
});

test('application log reader filters by level and search', function () {
    $reader = app(ApplicationLogReader::class);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(
        storage_path('logs/laravel-test.log'),
        implode("\n", [
            '[2026-06-10 10:00:00] local.INFO: Employee saved',
            '[2026-06-10 10:01:00] local.ERROR: File upload failed. {"reason":"validation"}',
            '[2026-06-10 10:02:00] local.WARNING: Disk almost full',
        ])."\n",
    );

    $result = $reader->paginate('laravel-test.log', 'error', 'upload', 1, 50);

    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['message'])->toBe('File upload failed.')
        ->and($result['entries'][0]['context']['reason'] ?? null)->toBe('validation');
});

test('guests cannot export application logs', function () {
    $this->get(route('log.export'))->assertRedirect(route('login'));
});

test('platform viewers can export the selected application log file as txt', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    $path = storage_path('logs/laravel-test-export.log');
    File::put($path, "[2026-06-10 10:00:00] local.ERROR: log details to export\n");

    $response = $this->get(route('log.export', ['file' => 'laravel-test-export.log']))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=laravel-test-export.txt');

    expect($response->streamedContent())->toContain('log details to export');

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Exported application logs')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('action'))->toBe('platform.logs.export')
        ->and($activity->properties->get('file'))->toBe('laravel-test-export.log');
});

test('exporting rejects invalid log file names', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/laravel.log'), "[2026-06-10 10:00:00] local.INFO: ok\n");

    $this->get(route('log.export', ['file' => '../.env']))->assertNotFound();
});
