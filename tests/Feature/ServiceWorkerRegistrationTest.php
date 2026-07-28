<?php

test('service worker route exposes root scope allow header', function () {
    $path = public_path('service-worker.js');

    expect(is_file($path))->toBeTrue();

    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');

    expect(file_get_contents($path))->toContain("addEventListener('push'");
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
