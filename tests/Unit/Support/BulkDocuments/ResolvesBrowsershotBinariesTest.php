<?php

use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;

test('resolves browsershot binaries from configured paths', function () {
    $node = storage_path('app/testing-node');
    $npm = storage_path('app/testing-npm');

    file_put_contents($node, "#!/bin/sh\necho node\n");
    file_put_contents($npm, "#!/bin/sh\necho npm\n");
    chmod($node, 0755);
    chmod($npm, 0755);

    config()->set('services.browsershot.node_binary', $node);
    config()->set('services.browsershot.npm_binary', $npm);

    expect(ResolvesBrowsershotBinaries::nodeBinary())->toBe($node)
        ->and(ResolvesBrowsershotBinaries::npmBinary())->toBe($npm);
});

test('resolve returns node and npm binaries when available on the host', function () {
    config()->set('services.browsershot.node_binary', null);
    config()->set('services.browsershot.npm_binary', null);

    $binaries = ResolvesBrowsershotBinaries::resolve();

    expect($binaries['node'])->not->toBeEmpty()
        ->and($binaries['npm'])->not->toBeEmpty();
});

test('resolves chrome headless shell from puppeteer cache directory', function () {
    $cacheDir = storage_path('app/testing-puppeteer-chrome-'.uniqid());
    $chromeDir = $cacheDir.'/chrome-headless-shell/linux-150.0.7871.24/chrome-headless-shell-linux64';
    mkdir($chromeDir, 0755, true);

    $chrome = $chromeDir.'/chrome-headless-shell';
    file_put_contents($chrome, "#!/bin/sh\necho chrome\n");
    chmod($chrome, 0755);

    config()->set('services.browsershot.chrome_path', null);
    config()->set('services.browsershot.puppeteer_cache_dir', $cacheDir);

    expect(ResolvesBrowsershotBinaries::chromePath())->toBe($chrome);
});

test('browsershot doctor command is registered', function () {
    $this->artisan('browsershot:doctor --help')
        ->assertSuccessful();
});
