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

test('authenticated users can clear the selected application log file', function () {
    $user = User::factory()->create();
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
});

test('authenticated users can clear all application log files', function () {
    $user = User::factory()->create();
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
