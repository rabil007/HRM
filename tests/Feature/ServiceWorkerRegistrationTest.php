<?php

test('service worker route exposes root scope allow header', function () {
    $path = public_path('service-worker.js');

    expect(is_file($path))->toBeTrue();

    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("object-src 'none'");

    expect(file_get_contents($path))->toContain("addEventListener('push'")
        ->and(file_get_contents($path))->toContain("addEventListener('fetch'")
        ->and(file_get_contents($path))->toContain('/offline.html')
        ->and(file_get_contents($path))->toContain('oms-hrm-offline-shell-');
});

test('offline fallback page ships as a static public asset', function () {
    $path = public_path('offline.html');

    expect(is_file($path))->toBeTrue();

    $html = (string) file_get_contents($path);

    expect($html)->toContain("You're Offline")
        ->and($html)->toContain('Try Again')
        ->and($html)->toContain('Connection restored');
});

test('service worker route returns not found when push worker is missing', function () {
    $path = public_path('service-worker.js');
    $backup = null;

    if (is_file($path)) {
        $backup = file_get_contents($path);
        unlink($path);
    }

    try {
        $this->get('/sw.js')->assertNotFound();
    } finally {
        if ($backup !== null) {
            file_put_contents($path, $backup);
        }
    }
});
