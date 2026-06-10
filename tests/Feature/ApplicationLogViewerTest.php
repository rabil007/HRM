<?php

use App\Models\User;
use App\Support\Logging\ApplicationLogReader;
use Illuminate\Support\Facades\File;

test('guests cannot view application logs', function () {
    $this->get('/log')->assertRedirect(route('login'));
});

test('authenticated users can view application logs', function () {
    $user = User::factory()->create();
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
            ->where('entries.0.level', 'error'));
});

test('application log viewer rejects invalid log file names', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/laravel.log'), "[2026-06-10 10:00:00] local.INFO: ok\n");

    $this->get('/log?file=../.env')->assertNotFound();
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
