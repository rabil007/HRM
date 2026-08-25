<?php

test('helpers used across Pest files live in tests/Support so shards can load them', function () {
    $root = dirname(__DIR__, 3);
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (str_starts_with($relative, 'tests/Support/')) {
            continue;
        }

        $files[$relative] = (string) file_get_contents($file->getPathname());
    }

    $defined = [];

    foreach ($files as $relative => $source) {
        if (! preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $source, $matches)) {
            continue;
        }

        foreach ($matches[1] as $function) {
            $defined[$function][] = $relative;
        }
    }

    $cross = [];

    foreach ($defined as $function => $owners) {
        foreach ($files as $relative => $source) {
            if (in_array($relative, $owners, true)) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($function, '/').'\s*\(/', $source) === 1) {
                $cross[] = $function.' defined in '.implode(', ', $owners).' used in '.$relative;
            }
        }
    }

    expect($cross)->toBeEmpty();
});
