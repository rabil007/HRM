<?php

use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;

test('browsershot pdf uses shared hosting chromium arguments', function () {
    expect(ConfiguresBrowsershotPdf::chromiumArguments())->toBe([
        'disable-dev-shm-usage',
        'disable-gpu',
        'disable-setuid-sandbox',
        'no-zygote',
        'single-process',
    ]);
});
